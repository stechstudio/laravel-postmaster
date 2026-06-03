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
}
