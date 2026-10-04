<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\Contracts\ProviderSetup;
use STS\Postmaster\Postmaster;
use STS\Postmaster\Providers\GenericSetup;

class ProviderSetupTest extends TestCase
{
    protected function resolve(string $name): ProviderSetup
    {
        return app(Postmaster::class)->setup($name);
    }

    public function testResolvesTheConfiguredSetupPerProvider()
    {
        $this->assertInstanceOf(\STS\Postmaster\Providers\SendGrid\Setup::class, $this->resolve('sendgrid'));
        $this->assertInstanceOf(\STS\Postmaster\Providers\Ses\Setup::class, $this->resolve('ses'));
    }

    public function testFallsBackToGenericSetupForAnUnknownProvider()
    {
        $setup = $this->resolve('madeup');

        $this->assertInstanceOf(GenericSetup::class, $setup);
        $this->assertSame('madeup', $setup->name());
    }

    public function testTransportAndSmtpMetadata()
    {
        $this->assertSame(['ses', 'ses-v2'], $this->resolve('ses')->transportNames());
        $this->assertContains('sendgrid.net', $this->resolve('sendgrid')->smtpHints());

        // SendGrid has no first-party transport — detection is host-only.
        $this->assertSame([], $this->resolve('sendgrid')->transportNames());
    }

    public function testFindsEachMailersSendingKeyWhereItsDriverLooks()
    {
        // Laravel's own transports fall back to config/services.php.
        $this->assertFalse($this->resolve('postmark')->sendingKeySet([]));
        $this->assertTrue($this->resolve('postmark')->sendingKeySet(['token' => 'x']));
        config(['services.postmark.key' => 'x']);
        $this->assertTrue($this->resolve('postmark')->sendingKeySet([]));

        $this->assertFalse($this->resolve('resend')->sendingKeySet([]));
        config(['services.resend.key' => 'x']);
        $this->assertTrue($this->resolve('resend')->sendingKeySet([]));

        $this->assertFalse($this->resolve('mailgun')->sendingKeySet([]));
        config(['services.mailgun.secret' => 'x']);
        $this->assertTrue($this->resolve('mailgun')->sendingKeySet([]));

        // The third-party drivers read their own config files.
        $this->assertFalse($this->resolve('helo')->sendingKeySet([]));
        config(['helo.key' => 'x']);
        $this->assertTrue($this->resolve('helo')->sendingKeySet([]));

        $this->assertFalse($this->resolve('mailersend')->sendingKeySet([]));
        config(['mailersend-driver.api_key' => 'x']);
        $this->assertTrue($this->resolve('mailersend')->sendingKeySet([]));
    }

    public function testCannotTellWhenSesUsesTheServersAwsRole()
    {
        $this->assertNull($this->resolve('ses')->sendingKeySet([]));
        $this->assertTrue($this->resolve('ses')->sendingKeySet(['key' => 'x', 'secret' => 'y']));
        $this->assertNull($this->resolve('sendgrid')->sendingKeySet([]));
    }

    public function testWebhookVerbIsTailoredForSes()
    {
        $this->assertSame('Subscribe an SNS topic to this URL', $this->resolve('ses')->webhookVerb());
        $this->assertStringContainsString('Resend', $this->resolve('resend')->webhookVerb());
    }

    public function testResendDeclaresNoSuppressionSync()
    {
        $this->assertFalse($this->resolve('resend')->supportsSuppressionSync());
        $this->assertTrue($this->resolve('sendgrid')->supportsSuppressionSync());
    }

    public function testAuthFailureGuidanceCallsOutAMissingOrPresentCredential()
    {
        config(['postmaster.providers.resend.signing_secret' => null]);
        $guidance = implode("\n", $this->resolve('resend')->webhookAuthGuidance());
        $this->assertStringContainsString('POSTMASTER_RESEND_SIGNING_SECRET is NOT set', $guidance);

        config(['postmaster.providers.resend.signing_secret' => 'whsec_abc']);
        $guidance = implode("\n", $this->resolve('resend')->webhookAuthGuidance());
        $this->assertStringContainsString('POSTMASTER_RESEND_SIGNING_SECRET is set', $guidance);
    }

    public function testPostmarkAuthFailureGuidanceIsModeAware()
    {
        config(['postmaster.providers.postmark.auth' => 'token']);
        $this->assertStringContainsString('token auth', implode("\n", $this->resolve('postmark')->webhookAuthGuidance()));

        config(['postmaster.providers.postmark.auth' => 'basic']);
        $this->assertStringContainsString('basic auth', implode("\n", $this->resolve('postmark')->webhookAuthGuidance()));
    }

    public function testHeloNamesThePackageOnEveryInteractiveSetup(): void
    {
        // askWebhookAuth runs whether or not the operator sets up sync.
        // An earlier artisan test leaves Prompts falling back to its mocked
        // console, and fallbackWhen() can only turn that on.
        (fn () => static::$shouldFallback = false)->bindTo(null, \Laravel\Prompts\Prompt::class)();
        \Laravel\Prompts\Prompt::fake(['k', 'e', 'y', \Laravel\Prompts\Key::ENTER]);

        $values = $this->resolve('helo')->askWebhookAuth();

        $this->assertSame(['POSTMASTER_HELO_SIGNING_KEY' => 'key'], $values);
        \Laravel\Prompts\Prompt::assertOutputContains('stechstudio/laravel-helo-email');
    }

    public function testHeloNamesThePackageInTheSetupReport(): void
    {
        $this->artisan('postmaster:install', ['--no-interaction' => true, '--provider' => 'helo'])
            ->expectsOutputToContain('stechstudio/laravel-helo-email');
    }

    public function testHeloSyncReadsThePackageConfig(): void
    {
        config(['helo.key' => 'api-key', 'helo.channel_id' => null]);
        $this->assertFalse((new \STS\Postmaster\Providers\Helo\SuppressionSync([]))->isAvailable());

        config(['helo.channel_id' => 'channel-id']);
        $this->assertTrue((new \STS\Postmaster\Providers\Helo\SuppressionSync([]))->isAvailable());
    }

    public function testMailerSendExplainsWebhookSecretsAndSyncScope(): void
    {
        config(['postmaster.providers.mailersend.signing_secret' => null]);
        $guidance = implode("\n", $this->resolve('mailersend')->webhookAuthGuidance());
        $this->assertStringContainsString('POSTMASTER_MAILERSEND_SIGNING_SECRET is NOT set', $guidance);
        $this->assertStringContainsString('version 2', $guidance);
        $this->assertTrue($this->resolve('mailersend')->supportsSuppressionSync());
    }

    public function testMailerSendAsksForTheSigningSecretTokenAndDomain(): void
    {
        (fn () => static::$shouldFallback = false)->bindTo(null, \Laravel\Prompts\Prompt::class)();
        config(['postmaster.providers.mailersend.api_key' => null, 'postmaster.providers.mailersend.domain_id' => null]);
        $setup = $this->resolve('mailersend');

        \Laravel\Prompts\Prompt::fake(['s', 'e', 'c', \Laravel\Prompts\Key::ENTER]);
        $this->assertSame(['POSTMASTER_MAILERSEND_SIGNING_SECRET' => 'sec'], $setup->askWebhookAuth());

        \Laravel\Prompts\Prompt::fake(['k', 'e', 'y', \Laravel\Prompts\Key::ENTER, 'd', 'o', 'm', \Laravel\Prompts\Key::ENTER]);
        $this->assertSame(['POSTMASTER_MAILERSEND_API_KEY' => 'key', 'POSTMASTER_MAILERSEND_DOMAIN_ID' => 'dom'], $setup->askSuppressionSync());
    }

    public function testMailerSendReusesTheDriversTokenAndAllowsEveryDomain(): void
    {
        (fn () => static::$shouldFallback = false)->bindTo(null, \Laravel\Prompts\Prompt::class)();
        config(['postmaster.providers.mailersend.api_key' => 'mlsn.key', 'postmaster.providers.mailersend.domain_id' => null]);

        \Laravel\Prompts\Prompt::fake([\Laravel\Prompts\Key::ENTER]);

        $this->assertSame([], $this->resolve('mailersend')->askSuppressionSync());
        \Laravel\Prompts\Prompt::assertOutputContains('Found a MailerSend API token');
    }
}
