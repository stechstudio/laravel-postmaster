<?php

namespace STS\Postmaster\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Providers\Postmark\Adapter as Postmark;
use STS\Postmaster\Tests\Stubs\FakeSync;
use STS\Postmaster\Tests\Stubs\User;

/**
 * Coverage for the attributed-activity additions: EmailAddress::suppress()
 * and unsuppress() write their own email_activity rows with causer / source
 * columns set, so a consumer can answer "who suppressed this address, when,
 * and why" by reading the ledger alone.
 */
class AttributedActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('mail.default', 'array');

        // A single fake provider keeps the sync-command tests from needing
        // any real SDK installed.
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

        // A minimal users table so causer tests can instantiate a real
        // model (rather than a fake whose getKey() returns null).
        Schema::create('users', fn ($table) => $table->id());
    }

    protected function tearDown(): void
    {
        // enforceMorphMap() flips a global flag and adds aliases that
        // persist across tests. The other suites use morph relations
        // (Order, Tenant) without a registered alias, so leaving strict
        // mode on or alias entries behind would error every later test.
        Relation::requireMorphMap(false);
        Relation::morphMap([], false);

        parent::tearDown();
    }

    public function testSuppressWritesAnActivityEntryAttributedToTheCauser()
    {
        // Map the test User to the 'user' morph alias so causer_type stores
        // a stable string ('user') rather than the FQCN — that's the whole
        // point of routing through getMorphClass() in EmailAddress::suppress().
        Relation::enforceMorphMap(['user' => User::class]);

        $user = User::create();

        $address = Postmaster::suppress(
            'alice@example.com',
            EmailAddress::REASON_MANUAL,
            causer: $user,
            source: 'dashboard',
        );

        $activity = $address->activity()->latest('id')->first();

        $this->assertSame(EmailActivity::STATUS_SUPPRESSED, $activity->status);
        $this->assertSame(EmailAddress::REASON_MANUAL, $activity->reason);
        $this->assertSame('user', $activity->causer_type);
        $this->assertSame((int) $user->getKey(), (int) $activity->causer_id);
        $this->assertSame('dashboard', $activity->source);
    }

    public function testUnsuppressWritesAnActivityEntryAttributedToTheCauser()
    {
        Relation::enforceMorphMap(['user' => User::class]);

        $user = User::create();

        EmailAddress::create([
            'address'       => 'alice@example.com',
            'status'        => EmailAddress::STATUS_SUPPRESSED,
            'reason'        => EmailAddress::REASON_BOUNCED,
            'providers'     => ['fake'],
            'suppressed_at' => now(),
        ]);

        Postmaster::unsuppress(
            'alice@example.com',
            causer: $user,
            source: 'dashboard',
            reason: 'Customer confirmed the mailbox was fixed',
        );

        $activity = EmailAddress::first()->activity()->latest('id')->first();

        $this->assertSame(EmailActivity::STATUS_UNSUPPRESSED, $activity->status);
        $this->assertSame('Customer confirmed the mailbox was fixed', $activity->reason);
        $this->assertSame('user', $activity->causer_type);
        $this->assertSame((int) $user->getKey(), (int) $activity->causer_id);
        $this->assertSame('dashboard', $activity->source);
        // The provider was cleared via FakeSync; that note still flows into
        // the activity entry's `response` blurb the way it did before.
        $this->assertSame('Cleared at: fake', $activity->response);
    }

    public function testCauserRelationHydratesTheCauserModel()
    {
        Relation::enforceMorphMap(['user' => User::class]);

        $user = User::create();

        Postmaster::suppress('alice@example.com', causer: $user, source: 'dashboard');

        $activity = EmailAddress::first()->activity()->latest('id')->first()->fresh();

        $this->assertInstanceOf(User::class, $activity->causer);
        $this->assertTrue($activity->causer->is($user));
    }

    public function testSuppressAndUnsuppressLeaveCauserNullWhenNoActorIsSupplied()
    {
        // Programmatic calls with no causer (e.g. from an artisan command)
        // still record the action — they just leave the attribution columns
        // null. `source` lets the caller label them anyway.
        $address = Postmaster::suppress('alice@example.com', source: 'console');

        $activity = $address->activity()->latest('id')->first();
        $this->assertNull($activity->causer_type);
        $this->assertNull($activity->causer_id);
        $this->assertSame('console', $activity->source);
    }

    public function testSuppressIsANoOpForTheLedgerWhenRecordEventsIsDisabled()
    {
        // The activity entry follows the same persistence.record_events
        // gate as every other logActivity() call — it should never become
        // the one ledger row that bypasses it.
        config(['postmaster.persistence.record_events' => false]);

        $address = Postmaster::suppress('alice@example.com', source: 'console');

        $this->assertTrue($address->isSuppressed());
        $this->assertSame(0, $address->activity()->count());
    }

    public function testEmailAddressSuppressWritesActivityWithoutGoingThroughTheFacade()
    {
        // Calling EmailAddress::suppress() directly — bypassing
        // Postmaster::suppress() — should still leave a ledger entry.
        // That's the whole regression the issue was filed about.
        Relation::enforceMorphMap(['user' => User::class]);

        $user = User::create();

        $address = EmailAddress::create(['address' => 'alice@example.com']);
        $address->suppress(EmailAddress::REASON_MANUAL, $user, 'unit-test');

        $activity = $address->activity()->latest('id')->first();
        $this->assertSame(EmailActivity::STATUS_SUPPRESSED, $activity->status);
        $this->assertSame('user', $activity->causer_type);
        $this->assertSame('unit-test', $activity->source);
    }

    public function testSyncCommandAttributesItsAddedAndClearedEntriesToSync()
    {
        // The reconciliation job has no model actor — it's run by cron —
        // but it should still leave a labelled trail in the ledger so a
        // consumer can tell sync-driven changes apart from operator ones.

        // Pre-suppress a bounced address locally so the next run's "no
        // longer in the provider's list" branch fires the clear path.
        EmailAddress::create([
            'address'       => 'bob@example.com',
            'status'        => EmailAddress::STATUS_SUPPRESSED,
            'reason'        => EmailAddress::REASON_BOUNCED,
            'providers'     => ['fake'],
            'suppressed_at' => now(),
        ]);

        FakeSync::$remote = ['alice@example.com' => EmailAddress::REASON_BOUNCED];

        $this->artisan('postmaster:sync')->assertSuccessful();

        $added = EmailAddress::where('address', 'alice@example.com')
            ->first()->activity()->latest('id')->first();

        $this->assertSame(EmailActivity::STATUS_SUPPRESSED, $added->status);
        $this->assertSame('sync', $added->source);

        $cleared = EmailAddress::where('address', 'bob@example.com')
            ->first()->activity()->latest('id')->first();

        $this->assertSame(EmailActivity::STATUS_UNSUPPRESSED, $cleared->status);
        $this->assertSame('sync', $cleared->source);
    }

    public function testWebhookAutoClearAttributesItsEntryToWebhook()
    {
        // A delivery on an automatically-suppressed address clears the
        // suppression and writes an unsuppressed entry — that path lives
        // in the listener, not on the model. Source = 'webhook' lets a
        // consumer tell it apart from operator-driven unsuppresses.
        config(['postmaster.persistence.track_addresses' => true]);

        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => 'auto-clear-1',
            'Email'      => 'recovered@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'auto-clear-2',
            'Recipient'   => 'recovered@example.com',
            'DeliveredAt' => '2021-02-01T00:00:00Z',
        ])));

        $latest = EmailAddress::where('address', 'recovered@example.com')
            ->first()->activity()->reorder('id', 'desc')->first();

        $this->assertSame(EmailActivity::STATUS_UNSUPPRESSED, $latest->status);
        $this->assertSame('webhook', $latest->source);
    }
}
