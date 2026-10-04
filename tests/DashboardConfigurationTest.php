<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailMessage;

class DashboardConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://app.example.com');
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.dashboard.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('mail.default', 'smtp');
        $app['config']->set('mail.mailers.smtp', ['transport' => 'smtp', 'host' => 'smtp.mailersend.net', 'port' => 587, 'username' => 'MS_user']);
        $app['config']->set('mail.from', ['address' => 'hello@example.com', 'name' => 'Example']);
        $app['config']->set('postmaster.providers.mailersend.signing_secret', 'whsec_super_secret_value');
        $app['config']->set('postmaster.providers.mailersend.api_key', 'mlsn.super_secret_token');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Postmaster::auth(fn () => true);
    }

    public function testListsThePageInTheNavigation(): void
    {
        $this->get('/postmaster')->assertOk()->assertSee(route('postmaster.configuration'), false);
    }

    public function testDescribesHowMailLeavesTheApp(): void
    {
        $this->get('/postmaster/configuration')
            ->assertOk()
            ->assertSeeInOrder(['Mailers', 'smtp', 'Default', 'MailerSend', 'SMTP', 'smtp.mailersend.net:587'])
            ->assertSee('hello@example.com')
            ->assertSee('https://app.example.com/webhooks/postmaster/mailersend');
    }

    public function testNeverShowsCredentials(): void
    {
        $this->get('/postmaster/configuration')
            ->assertOk()
            ->assertDontSee('whsec_super_secret_value')
            ->assertDontSee('mlsn.super_secret_token')
            ->assertSeeInOrder(['Login set', 'Ready', 'Ready']);
    }

    public function testLeavesOutMailersThatAreNotSetUp(): void
    {
        // Laravel's stock config defines postmark, resend, and ses mailers
        // whether or not the app uses them.
        config(['mail.mailers.postmark' => ['transport' => 'postmark'], 'services.postmark' => []]);

        $this->get('/postmaster/configuration')
            ->assertOk()
            ->assertDontSee('postmark')
            ->assertDontSee('Postmark')
            ->assertDontSee('/webhooks/postmaster/resend');
    }

    public function testListsOtherMailersThatAreSetUp(): void
    {
        config(['mail.mailers.bulk' => ['transport' => 'smtp', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'username' => 'apikey']]);

        $this->get('/postmaster/configuration')
            ->assertOk()
            ->assertSeeInOrder(['smtp', 'MailerSend', 'bulk', 'SendGrid', 'smtp.sendgrid.net:587']);
    }

    protected function sent(string $mailer, ?string $provider, string $status = 'delivered', int $daysAgo = 0): EmailMessage
    {
        $message = EmailMessage::create(['provider_message_id' => uniqid(), 'mailer' => $mailer, 'provider' => $provider, 'status' => $status]);
        $message->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $message;
    }

    public function testCallsAMailerWorkingOnlyOnceAWebhookConfirmsASend(): void
    {
        $this->get('/postmaster/configuration')->assertOk()->assertSee('Unconfirmed')->assertDontSee('Working');

        $this->sent('smtp', 'MailerSend');

        $this->get('/postmaster/configuration')->assertOk()->assertSee('Working')->assertSee('1 confirmed by webhook');
    }

    public function testIgnoresConfirmationsOlderThanThirtyDays(): void
    {
        $this->sent('smtp', 'MailerSend', daysAgo: 45);

        $this->get('/postmaster/configuration')->assertOk()->assertDontSee('Working');
    }

    public function testFlagsSendsThatNoWebhookConfirmed(): void
    {
        $this->sent('smtp', null, 'sent');
        $this->sent('smtp', null, 'sent');

        $this->get('/postmaster/configuration')->assertOk()->assertSee('No webhooks back')->assertSee('2 sent');
    }

    public function testDoesNotCountMailThatNeverLeft(): void
    {
        $this->sent('smtp', null, 'sandboxed');
        $this->sent('smtp', null, 'blocked');

        $this->get('/postmaster/configuration')->assertOk()->assertSee('Unconfirmed')->assertDontSee('No webhooks back');
    }

    public function testExplainsThatOlderSendsAreNotCounted(): void
    {
        $this->get('/postmaster/configuration')->assertOk()->assertDontSee('isn\'t counted', false);

        EmailMessage::create(['provider_message_id' => 'old', 'status' => 'delivered', 'sent_at' => now()]);

        $this->get('/postmaster/configuration')->assertOk()->assertSee('isn\'t counted', false);
    }

    public function testShowsARecentlyUsedMailerThatIsNoLongerSetUp(): void
    {
        $this->sent('newsletter', 'Helo');

        $this->get('/postmaster/configuration')
            ->assertOk()
            ->assertSeeInOrder(['newsletter', 'Helo', 'No longer configured']);
    }

    public function testCannotTrackAnUnknownSmtpServer(): void
    {
        config(['mail.mailers.smtp.host' => 'mail.example.net']);

        $this->get('/postmaster/configuration')->assertOk()->assertSeeInOrder(['smtp', 'Unknown', 'mail.example.net:587', 'Not tracked']);
    }

    public function testNamesTheProviderBehindAnUnknownSmtpServerFromItsWebhooks(): void
    {
        config(['mail.mailers.smtp.host' => 'mail.example.net']);
        $this->sent('smtp', 'SendGrid');

        // Once its webhooks name the provider, its webhook setup shows too.
        $this->get('/postmaster/configuration')->assertOk()->assertSeeInOrder(['smtp', 'SendGrid', 'mail.example.net:587', 'Secret missing', 'Working']);
    }

    public function testFlagsADefaultMailerWithoutItsKey(): void
    {
        config([
            'mail.default' => 'postmark',
            'mail.mailers.postmark' => ['transport' => 'postmark'],
            'services.postmark' => [],
        ]);

        $this->get('/postmaster/configuration')->assertOk()->assertSeeInOrder(['postmark', 'Key missing', 'Not set up']);
    }

    public function testFlagsADriverThatWillNotLoad(): void
    {
        // Nothing registers a "mailersend" transport in this app.
        config([
            'mail.default' => 'mailersend',
            'mail.mailers.mailersend' => ['transport' => 'mailersend', 'api_key' => 'mlsn.key'],
        ]);

        $this->get('/postmaster/configuration')->assertOk()->assertSeeInOrder(['mailersend', 'Driver didn\'t load', 'Not set up']);
    }

    public function testFlagsASendingProviderThatCannotSync(): void
    {
        // Postmaster reads provider config once, when it's built.
        config(['postmaster.providers.mailersend.api_key' => null]);
        $this->app->forgetInstance('postmaster');
        Postmaster::clearResolvedInstance('postmaster');
        Postmaster::auth(fn () => true);

        $this->get('/postmaster/configuration')
            ->assertOk()
            ->assertSee('Needs key')
            ->assertSee('MailerSend suppressions don\'t sync');
    }

    public function testLeavesOutDashboardAndEnvironmentDetails(): void
    {
        $this->get('/postmaster/configuration')
            ->assertOk()
            ->assertDontSee('Resend cooldown')
            ->assertDontSee('Middleware')
            ->assertDontSee('Default queue');
    }

    public function testFlagsSandboxDelivery(): void
    {
        $this->get('/postmaster/configuration')->assertOk()->assertDontSee('No mail leaves this app');

        config(['postmaster.delivery' => 'sandbox']);

        $this->get('/postmaster/configuration')->assertOk()->assertSee('Sandbox')->assertSee('No mail leaves this app');
    }

    public function testFlagsAMissingWebhookCredentialForTheSendingProvider(): void
    {
        config(['postmaster.providers.mailersend.signing_secret' => null]);

        $this->get('/postmaster/configuration')->assertOk()->assertSee('rejects every MailerSend webhook');
    }

    public function testFlagsSettingsThatCannotTakeEffect(): void
    {
        config([
            'postmaster.block_suppressed' => true,
            'postmaster.persistence.track_addresses' => false,
            'postmaster.queue_webhooks' => true,
            'postmaster.queue_connection' => 'sync',
            'postmaster.persistence.store_content' => true,
            'postmaster.persistence.prune_content_after_days' => 0,
        ]);

        $this->get('/postmaster/configuration')
            ->assertOk()
            ->assertSee('Address tracking is off')
            ->assertSee('the sync queue connection')
            ->assertSee('Stored message content is never deleted');
    }

    public function testFlagsAWebhookUrlProvidersCannotReach(): void
    {
        config(['app.url' => 'http://postmaster.test']);

        $this->get('/postmaster/configuration')->assertOk()->assertSee('can\'t reach');
    }

    public function testShowsRetentionWindowsAndTheSchedule(): void
    {
        config(['postmaster.persistence.prune_failed_activity_after_days' => 0]);

        $this->get('/postmaster/configuration')
            ->assertOk()
            ->assertSeeInOrder(['Retention', '30 days', '30 days', '90 days', 'Forever'])
            ->assertSee('Daily at 03:00')
            ->assertSee('Daily at 04:00');
    }

    public function testShowsAFailoverChain(): void
    {
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['resend', 'log']],
            'mail.mailers.resend' => ['transport' => 'resend'],
            'services.resend' => ['key' => 're_key'],
        ]);
        // Sends through a failover chain record the chain's name.
        $this->sent('failover', 'Resend');

        $this->get('/postmaster/configuration')
            ->assertOk()
            ->assertSeeInOrder(['resend', 'Default, 1st', 'Resend', 'Working', 'log', 'Default, 2nd', 'Doesn\'t deliver']);
    }

    public function testShowsWhenTheLastWebhookArrived(): void
    {
        $this->get('/postmaster/configuration')->assertOk()->assertSee('None recorded');

        $message = EmailMessage::create(['provider_message_id' => 'm1', 'status' => 'delivered']);
        EmailActivity::create(['email_message_id' => $message->id, 'provider' => 'MailerSend', 'status' => 'delivered', 'occurred_at' => now()]);

        $this->get('/postmaster/configuration')->assertOk()->assertSeeInOrder(['Last webhook', 'MailerSend']);
    }

    public function testWorksWithoutTheTimeline(): void
    {
        config(['postmaster.persistence.record_events' => false]);

        $this->get('/postmaster/configuration')->assertOk()->assertSee('Timeline');
    }
}
