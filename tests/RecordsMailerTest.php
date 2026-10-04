<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Models\EmailMessage;

/**
 * Each recorded message keeps the name of the mailer that sent it, so the
 * configuration page can tell which mailers are in use.
 */
class RecordsMailerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('mail.default', 'transactional');
        $app['config']->set('mail.mailers.transactional', ['transport' => 'array']);
        $app['config']->set('mail.mailers.bulk', ['transport' => 'log']);
    }

    protected function send(?string $mailer = null): void
    {
        Mail::mailer($mailer)->raw('Body', fn ($mail) => $mail->to('user@example.com')->subject('Hello'));
    }

    public function testRecordsTheDefaultMailer(): void
    {
        $this->send();

        $this->assertSame('transactional', EmailMessage::sole()->mailer);
    }

    public function testRecordsAMailerChosenForOneSend(): void
    {
        $this->send('bulk');

        $this->assertSame('bulk', EmailMessage::sole()->mailer);
    }

    public function testReadsTheStatusFromTheMailerThatSent(): void
    {
        // The default mailer is "array"; this send went through "log".
        $this->send('bulk');

        $this->assertSame(EmailEvent::STATUS_LOGGED, EmailMessage::sole()->status);
    }
}
