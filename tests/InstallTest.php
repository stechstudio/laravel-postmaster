<?php

namespace STS\Postmaster\Tests;

use ReflectionMethod;
use STS\Postmaster\Console\Install;

class InstallTest extends TestCase
{
    /**
     * Drive the command's protected detectProvider() against a given mail
     * mailer config.
     *
     * @param array<string, mixed> $mailer
     */
    protected function detectFor(array $mailer): ?string
    {
        config(['mail.default' => 'x', 'mail.mailers.x' => $mailer]);

        $method = new ReflectionMethod(Install::class, 'detectProvider');
        $method->setAccessible(true);

        return $method->invoke(new Install);
    }

    public function testDetectsProviderFromDirectTransport()
    {
        $this->assertSame('postmark', $this->detectFor(['transport' => 'postmark']));
        $this->assertSame('mailgun', $this->detectFor(['transport' => 'mailgun']));
        $this->assertSame('resend', $this->detectFor(['transport' => 'resend']));
        $this->assertSame('ses', $this->detectFor(['transport' => 'ses-v2']));
    }

    public function testDetectsProviderFromSmtpHost()
    {
        $this->assertSame('sendgrid', $this->detectFor(['transport' => 'smtp', 'host' => 'smtp.sendgrid.net']));
        $this->assertSame('postmark', $this->detectFor(['transport' => 'smtp', 'host' => 'smtp.postmarkapp.com']));
        $this->assertSame('mailgun', $this->detectFor(['transport' => 'smtp', 'host' => 'smtp.mailgun.org']));
    }

    public function testReturnsNullWhenUnrecognized()
    {
        $this->assertNull($this->detectFor(['transport' => 'smtp', 'host' => 'mail.example.com']));
        $this->assertNull($this->detectFor(['transport' => 'array']));
    }

    public function testNonInteractiveInstallReportsSetupForTheDetectedProvider()
    {
        config(['mail.default' => 'postmark', 'mail.mailers.postmark' => ['transport' => 'postmark']]);

        $this->artisan('postmaster:install', ['--no-interaction' => true])
            ->expectsOutputToContain('non-interactive')
            ->expectsOutputToContain('webhooks/postmaster/postmark')
            ->assertExitCode(0);
    }

    public function testNonInteractiveInstallHonorsTheProviderOption()
    {
        $this->artisan('postmaster:install', ['--no-interaction' => true, '--provider' => 'sendgrid'])
            ->expectsOutputToContain('webhooks/postmaster/sendgrid')
            ->assertExitCode(0);
    }

    public function testNonInteractiveInstallFailsOnAnUnknownProvider()
    {
        $this->artisan('postmaster:install', ['--no-interaction' => true, '--provider' => 'nope'])
            ->expectsOutputToContain('Unknown provider "nope"')
            ->assertExitCode(1);
    }

    public function testNonInteractiveInstallFailsWhenProviderCannotBeDetermined()
    {
        config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);

        $this->artisan('postmaster:install', ['--no-interaction' => true])
            ->expectsOutputToContain('Could not determine the provider')
            ->assertExitCode(1);
    }

    public function testNonInteractiveInstallWarnsWhenWebhookAuthIsMissing()
    {
        // Postmark basic-auth credentials aren't set, so auth is incomplete —
        // surfaced as a warning, but the report still succeeds.
        config(['postmaster.basic_username' => null, 'postmaster.basic_password' => null]);

        $this->artisan('postmaster:install', ['--no-interaction' => true, '--provider' => 'postmark'])
            ->expectsOutputToContain('not configured')
            ->assertExitCode(0);
    }

    public function testNonInteractiveInstallReportsConfiguredWebhookAuth()
    {
        config([
            'postmaster.providers.postmark.auth' => 'basic',
            'postmaster.basic_username'          => 'hook',
            'postmaster.basic_password'          => 'secret',
        ]);

        $this->artisan('postmaster:install', ['--no-interaction' => true, '--provider' => 'postmark'])
            ->doesntExpectOutputToContain('not configured')
            ->assertExitCode(0);
    }
}
