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
 * Helo's SMTP server ends DATA with a bare "250 <uuid>", the same id its
 * webhooks carry. Symfony only parses "250 Ok ..." replies, so it falls
 * back to the Message-ID header and webhooks could not correlate.
 */
class HeloSmtpCorrelationTest extends TestCase
{
    protected function resolve(string $debug): ?string
    {
        $email = (new Email)->from('sender@example.com')->to('recipient@example.com')->subject('test')->html('<p>hi</p>');
        $sent = new SentMessage($email, new Envelope(new Address('sender@example.com'), [new Address('recipient@example.com')]));
        $sent->appendDebug($debug);

        $listener = new class(app(Postmaster::class)) extends RecordOutboundMessage {
            public function exposedResolveProviderMessageId(MessageSent $event): ?string
            {
                return $this->resolveProviderMessageId($event);
            }
        };

        return $listener->exposedResolveProviderMessageId(new MessageSent(new LaravelSentMessage($sent), []));
    }

    public function testUsesTheIdFromHelosFinalSmtpReply(): void
    {
        // Captured from smtp.helohq.com, with the login exchange removed.
        $debug = <<<'SMTP'
            [2026-10-03T11:32:07.289521Z] < 220 Helo SMTP v1.0.0.0 ESMTP ready
            [2026-10-03T11:32:07.604761Z] > MAIL FROM:<sender@example.com>
            [2026-10-03T11:32:07.641495Z] < 250 Ok
            [2026-10-03T11:32:07.641531Z] > RCPT TO:<recipient@example.com>
            [2026-10-03T11:32:07.675750Z] < 250 Ok
            [2026-10-03T11:32:07.675787Z] > DATA
            [2026-10-03T11:32:07.709112Z] < 354 end with <CRLF>.<CRLF>
            [2026-10-03T11:32:07.711836Z] > .
            [2026-10-03T11:32:07.920054Z] < 250 01a10189-2674-7f23-b2ce-3e318cbbfea3

            SMTP;

        $this->assertSame('01a10189-2674-7f23-b2ce-3e318cbbfea3', $this->resolve($debug));
    }

    public function testVerifyWatchesForTheIdWebhooksWillCarry(): void
    {
        // A transport that ends like Helo's SMTP server does.
        app('mail.manager')->extend('helo-smtp-stub', fn () => new class extends \Symfony\Component\Mailer\Transport\AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                $message->appendDebug("< 250 01a10189-2674-7f23-b2ce-3e318cbbfea3\r\n");
            }

            public function __toString(): string
            {
                return 'helo-smtp-stub';
            }
        });
        config(['mail.mailers.helo-smtp-stub' => ['transport' => 'helo-smtp-stub'], 'mail.default' => 'helo-smtp-stub']);

        $verify = new class extends \STS\Postmaster\Console\Verify {
            public function exposedSendTestEmail(string $address): string|null|false
            {
                return $this->sendTestEmail($address);
            }
        };

        $this->assertSame('01a10189-2674-7f23-b2ce-3e318cbbfea3', $verify->exposedSendTestEmail('recipient@example.com'));
    }

    public function testLeavesOtherSmtpRepliesToSymfony(): void
    {
        $debug = "[2026-10-03T11:32:07Z] > .\r\n[2026-10-03T11:32:07Z] < 250 2.0.0 OK  1791027019 5614622812f47 - gsmtp\r\n";

        $this->assertStringEndsWith('@example.com', $this->resolve($debug));
    }
}
