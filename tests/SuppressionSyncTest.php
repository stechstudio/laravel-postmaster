<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Tests\Stubs\FakeSync;

class SuppressionSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('mail.default', 'array');

        // Use a single fake provider for the reconciliation tests instead
        // of the five real providers (whose SDKs aren't installed).
        $app['config']->set('postmaster.providers', [
            'fake' => [
                'adapter' => \STS\Postmaster\Providers\Postmark\Adapter::class,
                'auth'    => 'basic',
                'sync'    => FakeSync::class,
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        FakeSync::reset();
    }

    public function testPostmasterSyncLookupIsCaseInsensitive()
    {
        // The providers JSON column on email_addresses stores the canonical
        // product name ("Postmark", "SendGrid"); the config keys are
        // lowercase identifiers. Postmaster::sync() must accept either, or
        // unsuppress() can't find the sync class for a recorded provider.
        $pm = app(\STS\Postmaster\Postmaster::class);

        $this->assertInstanceOf(FakeSync::class, $pm->sync('fake'));
        $this->assertInstanceOf(FakeSync::class, $pm->sync('Fake'));
        $this->assertInstanceOf(FakeSync::class, $pm->sync('FAKE'));
        $this->assertNull($pm->sync('nonexistent'));
    }

    public function testSyncAddsProviderSuppressionsToTheLocalTable()
    {
        FakeSync::$remote = [
            'alice@example.com' => EmailAddress::REASON_BOUNCED,
            'bob@example.com'   => EmailAddress::REASON_COMPLAINED,
        ];

        $this->artisan('postmaster:sync')->assertSuccessful();

        $alice = EmailAddress::where('address', 'alice@example.com')->first();
        $bob   = EmailAddress::where('address', 'bob@example.com')->first();

        $this->assertTrue($alice->isSuppressed());
        $this->assertSame(EmailAddress::REASON_BOUNCED, $alice->reason);
        $this->assertTrue($bob->isSuppressed());
        $this->assertSame(EmailAddress::REASON_COMPLAINED, $bob->reason);
    }

    public function testSyncClearsLocalAutomaticSuppressionsTheProviderNoLongerHolds()
    {
        // Alice was suppressed locally via a webhook bounce. The provider's
        // admin has since unsuppressed her in their dashboard — sync should
        // pick that up and clear her local row too.
        $alice = EmailAddress::create([
            'address'       => 'alice@example.com',
            'status'        => EmailAddress::STATUS_SUPPRESSED,
            'reason'        => EmailAddress::REASON_BOUNCED,
            'suppressed_at' => now()->subDays(5),
        ]);

        FakeSync::$remote = []; // provider lists nothing

        $this->artisan('postmaster:sync')->assertSuccessful();

        $this->assertSame(EmailAddress::STATUS_ACTIVE, $alice->fresh()->status);
    }

    public function testSyncNeverAutoClearsManualSuppressions()
    {
        // An admin manually suppressed bob — for a reason the provider
        // doesn't know about. Sync must leave that row alone.
        $bob = EmailAddress::create([
            'address'       => 'bob@example.com',
            'status'        => EmailAddress::STATUS_SUPPRESSED,
            'reason'        => EmailAddress::REASON_MANUAL,
            'suppressed_at' => now()->subDays(5),
        ]);

        FakeSync::$remote = [];

        $this->artisan('postmaster:sync')->assertSuccessful();

        $this->assertTrue($bob->fresh()->isSuppressed());
        $this->assertSame(EmailAddress::REASON_MANUAL, $bob->fresh()->reason);
    }

    public function testSyncDryRunReportsButDoesNotWrite()
    {
        FakeSync::$remote = ['alice@example.com' => EmailAddress::REASON_BOUNCED];

        $this->artisan('postmaster:sync', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, EmailAddress::count());
    }

    public function testSyncRejectsAnUnknownProvider()
    {
        $this->artisan('postmaster:sync', ['--provider' => 'sendgird'])
            ->assertFailed()
            ->expectsOutputToContain('Unknown provider "sendgird"');
    }

    public function testSyncSkipsProvidersThatReportUnavailable()
    {
        FakeSync::$available = false;
        FakeSync::$remote    = ['alice@example.com' => EmailAddress::REASON_BOUNCED];

        $this->artisan('postmaster:sync')
            ->assertSuccessful()
            ->expectsOutputToContain('skipped');

        $this->assertSame(0, EmailAddress::count());
    }

    public function testPostmasterUnsuppressAlsoCallsTheProviderSync()
    {
        // Pre-existing suppression in the local table — with the provider
        // recorded on the row, so unsuppress knows where to forward.
        EmailAddress::create([
            'address'       => 'alice@example.com',
            'status'        => EmailAddress::STATUS_SUPPRESSED,
            'reason'        => EmailAddress::REASON_BOUNCED,
            'providers'     => ['fake'],
            'suppressed_at' => now(),
        ]);

        $result = Postmaster::unsuppress('Alice@Example.com');

        // Local row lifted.
        $this->assertSame(EmailAddress::STATUS_ACTIVE, EmailAddress::first()->status);

        // Provider's API was called too, with the lowercased address.
        $this->assertSame(['alice@example.com'], FakeSync::$unsuppressed);

        // The result reports which providers were cleared and which need
        // manual cleanup — the dashboard's flash message uses this.
        $this->assertSame(['fake'], $result['cleared']);
        $this->assertSame([], $result['manual']);
    }

    public function testPostmasterSuppressLogsAnAddressActivityEntry()
    {
        Postmaster::suppress('alice@example.com');

        $address = EmailAddress::first();
        $activity = $address->activity()->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame(\STS\Postmaster\Models\EmailActivity::STATUS_SUPPRESSED, $activity->status);
        $this->assertSame(EmailAddress::REASON_MANUAL, $activity->reason);
        $this->assertNull($activity->email_message_id);
    }

    public function testPostmasterUnsuppressLogsAnAddressActivityEntry()
    {
        EmailAddress::create([
            'address'       => 'alice@example.com',
            'status'        => EmailAddress::STATUS_SUPPRESSED,
            'reason'        => EmailAddress::REASON_BOUNCED,
            'providers'     => ['fake'],
            'suppressed_at' => now(),
        ]);

        Postmaster::unsuppress('alice@example.com');

        $address = EmailAddress::first();
        $activity = $address->activity()->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame(\STS\Postmaster\Models\EmailActivity::STATUS_UNSUPPRESSED, $activity->status);
        $this->assertNull($activity->email_message_id);
        $this->assertSame((int) $address->getKey(), (int) $activity->email_address_id);
    }

    public function testSyncLogsAnAddressActivityEntryWhenAddingASuppression()
    {
        FakeSync::$remote = ['alice@example.com' => EmailAddress::REASON_BOUNCED];

        $this->artisan('postmaster:sync')->assertSuccessful();

        $activity = EmailAddress::first()->activity()->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame(\STS\Postmaster\Models\EmailActivity::STATUS_SUPPRESSED, $activity->status);
        $this->assertSame(EmailAddress::REASON_BOUNCED, $activity->reason);
        $this->assertSame('fake', $activity->provider);
    }

    public function testUnsuppressReportsProvidersThatNeedManualCleanup()
    {
        FakeSync::$available = false;

        EmailAddress::create([
            'address'       => 'alice@example.com',
            'status'        => EmailAddress::STATUS_SUPPRESSED,
            'reason'        => EmailAddress::REASON_BOUNCED,
            'providers'     => ['fake'],
            'suppressed_at' => now(),
        ]);

        $result = Postmaster::unsuppress('alice@example.com');

        $this->assertSame(EmailAddress::STATUS_ACTIVE, EmailAddress::first()->status);
        $this->assertSame([], $result['cleared']);
        $this->assertSame(['fake'], $result['manual']);
    }
}
