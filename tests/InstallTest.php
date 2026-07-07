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

    public function testCanPromptIsFalseWhenInputIsNotInteractive()
    {
        // Regression: non-interactive input — whether from --no-interaction or
        // a no-TTY platform like Laravel Cloud — must route to the report path,
        // never attempt a prompt (which would throw "Required.").
        $command = new Install;

        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $input->setInteractive(false);

        $property = new \ReflectionProperty($command, 'input');
        $property->setAccessible(true);
        $property->setValue($command, $input);

        $method = new \ReflectionMethod($command, 'canPrompt');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($command));
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
            ->expectsOutputToContain('every inbound webhook is rejected')
            ->assertExitCode(0);
    }

    public function testNonInteractiveInstallShowsAuthSetupEvenWhenConfigured()
    {
        config([
            'postmaster.providers.postmark.auth' => 'basic',
            'postmaster.basic_username'          => 'hook',
            'postmaster.basic_password'          => 'secret',
        ]);

        // The whole point: the provider-side auth setup steps are shown even
        // when the .env credential is present — a registered URL alone won't
        // authenticate. The env var names appear; secret values never do.
        $this->artisan('postmaster:install', ['--no-interaction' => true, '--provider' => 'postmark'])
            ->doesntExpectOutputToContain('not configured')
            ->expectsOutputToContain('Basic auth')
            ->expectsOutputToContain('POSTMASTER_AUTH_USERNAME')
            ->doesntExpectOutputToContain('secret')   // the password value is never echoed
            ->assertExitCode(0);
    }
}
