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
        $guidance = implode("\n", $this->resolve('resend')->authFailureGuidance());
        $this->assertStringContainsString('POSTMASTER_RESEND_SIGNING_SECRET is NOT set', $guidance);

        config(['postmaster.providers.resend.signing_secret' => 'whsec_abc']);
        $guidance = implode("\n", $this->resolve('resend')->authFailureGuidance());
        $this->assertStringContainsString('POSTMASTER_RESEND_SIGNING_SECRET is set', $guidance);
    }

    public function testPostmarkAuthFailureGuidanceIsModeAware()
    {
        config(['postmaster.providers.postmark.auth' => 'token']);
        $this->assertStringContainsString('token auth', implode("\n", $this->resolve('postmark')->authFailureGuidance()));

        config(['postmaster.providers.postmark.auth' => 'basic']);
        $this->assertStringContainsString('basic auth', implode("\n", $this->resolve('postmark')->authFailureGuidance()));
    }
}
