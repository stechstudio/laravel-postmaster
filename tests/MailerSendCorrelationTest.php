<?php

namespace STS\Postmaster\Tests;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as LaravelSentMessage;
use STS\Postmaster\Listeners\RecordOutboundMessage;
use STS\Postmaster\Postmaster;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Webhooks carry MailerSend's message id. Symfony's API bridge returns it
 * from getMessageId(); the other two ways of sending hide it elsewhere.
 */
class MailerSendCorrelationTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('postmaster.persistence.enabled', false);
    }

    protected function resolve(SentMessage $sent): ?string
    {
        $listener = new class(app(Postmaster::class)) extends RecordOutboundMessage {
            public function exposedResolveProviderMessageId(MessageSent $event): ?string
            {
                return $this->resolveProviderMessageId($event);
            }
        };

        return $listener->exposedResolveProviderMessageId(new MessageSent(new LaravelSentMessage($sent), []));
    }

    protected function sent(?Email $email = null): SentMessage
    {
        $email ??= (new Email)->from('sender@example.com')->to('recipient@example.com')->subject('test')->html('<p>hi</p>');

        return new SentMessage($email, new Envelope(new Address('sender@example.com'), [new Address('recipient@example.com')]));
    }

    public function testReadsTheHeaderMailerSendsLaravelDriverAdds(): void
    {
        // mailersend/laravel-driver stamps the API's x-message-id on the
        // original message instead of setting the sent message's id.
        $email = (new Email)->from('sender@example.com')->to('recipient@example.com')->subject('test')->html('<p>hi</p>');
        $email->getHeaders()->addTextHeader('X-MailerSend-Message-Id', '6892766ae78995a317577aa1');

        $this->assertSame('6892766ae78995a317577aa1', $this->resolve($this->sent($email)));
    }

    public function testUsesTheIdFromMailerSendsFinalSmtpReply(): void
    {
        // From MailerSend's SMTP relay docs. Symfony only parses "250 Ok ...".
        $sent = $this->sent();
        $sent->appendDebug(implode("\n", [
            '[2026-10-03T11:32:07.641495Z] < 250 2.1.0 Ok',
            '[2026-10-03T11:32:07.675787Z] > DATA',
            '[2026-10-03T11:32:07.709112Z] < 354 End data with <CR><LF>.<CR><LF>',
            '[2026-10-03T11:32:07.711836Z] > .',
            '[2026-10-03T11:32:07.920054Z] < 250 Message queued as 61eec2dc16ae8b627a4b87e7',
            '',
        ]));

        $this->assertSame('61eec2dc16ae8b627a4b87e7', $this->resolve($sent));
    }
}
