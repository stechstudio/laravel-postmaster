<?php

namespace STS\Postmaster\Tests;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as LaravelSentMessage;
use STS\Postmaster\Listeners\RecordOutboundMessage;
use STS\Postmaster\Postmaster;
use STS\Postmaster\Providers\Mailgun\Adapter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Regression for the Mailgun correlation bugs found in live testing.
 *
 * Two distinct mismatches that combined into one symptom — a phantom
 * second row at status=sent that never advanced.
 */
class MailgunCorrelationTest extends TestCase
{
    public function testProviderMessageIdReadsTheMessageHeaderNotMailgunsEventId()
    {
        // Mailgun's payload exposes two ids:
        //   - top-level `id`: a short base64 token, separate per event
        //   - event-data.message.headers.message-id: the real Message-ID
        //     header that matches what Symfony's Mailgun transport stored
        //     at send time. Only the latter correlates.
        $adapter = new Adapter([
            'signature'  => ['timestamp' => '1', 'token' => 't', 'signature' => 's'],
            'event-data' => [
                'event'     => 'delivered',
                'recipient' => 'r@example.com',
                'timestamp' => 1779919624,
                'id'        => 'Nz5rUz2sT6OY5t7hJt2WsA',
                'message'   => [
                    'headers' => [
                        'message-id' => 'abcd1234@example.com',
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            'abcd1234@example.com',
            $adapter->providerMessageId(),
            'Bug: extracted Mailgun event id instead of the email Message-ID — webhook can never correlate to the recorded send.'
        );
    }

    public function testResolveProviderMessageIdStripsAngleBracketsAtStorageTime()
    {
        // Symfony's Mailgun transport stores the Message-ID with angle
        // brackets (the value Mailgun's API returns); Mailgun's webhook
        // payload strips them. Normalize on storage so they meet in the
        // middle as a bare value.
        $email = (new Email)
            ->from('hello@example.com')
            ->to('r@example.com')
            ->subject('test')
            ->html('<p>hi</p>');

        $sent = new SentMessage($email, new Envelope(new Address('hello@example.com'), [new Address('r@example.com')]));
        $sent->setMessageId('<wrapped-id@example.com>');

        $event = new MessageSent(new LaravelSentMessage($sent), []);

        $listener = new class(app(Postmaster::class)) extends RecordOutboundMessage {
            public function exposedResolveProviderMessageId(MessageSent $event): ?string
            {
                return $this->resolveProviderMessageId($event);
            }
        };

        $this->assertSame(
            'wrapped-id@example.com',
            $listener->exposedResolveProviderMessageId($event),
            'Bug: stored ids retain Mailgun-style angle brackets; webhook delivers bare. They never match.'
        );
    }
}
