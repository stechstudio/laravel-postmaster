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
        // Pre-existing suppression in the local table.
        EmailAddress::create([
            'address'       => 'alice@example.com',
            'status'        => EmailAddress::STATUS_SUPPRESSED,
            'reason'        => EmailAddress::REASON_BOUNCED,
            'suppressed_at' => now(),
        ]);

        Postmaster::unsuppress('Alice@Example.com');

        // Local row lifted.
        $this->assertSame(EmailAddress::STATUS_ACTIVE, EmailAddress::first()->status);

        // Provider's API was called too, with the lowercased address.
        $this->assertSame(['alice@example.com'], FakeSync::$unsuppressed);
    }
}
