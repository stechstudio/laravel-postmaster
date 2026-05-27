<?php

namespace STS\Postmaster\Tests;

use Illuminate\Mail\Events\MessageSent;
use STS\Postmaster\Listeners\RecordOutboundMessage;
use STS\Postmaster\Postmaster;
use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Regression for the Resend correlation bug.
 *
 * Laravel's home-grown ResendTransport stamps Resend's email id on the
 * outgoing message as an X-Resend-Email-ID header but doesn't set it on
 * the SentMessage. \$event->sent->getMessageId() returns Symfony's auto-
 * generated id, so webhooks (which carry Resend's UUID) couldn't correlate
 * and we recorded a phantom second row. RecordOutboundMessage now prefers
 * the header where it's present.
 */
class ResendMessageIdCorrelationTest extends TestCase
{
    public function testProviderMessageIdPrefersResendHeaderOverSentMessageId()
    {
        $email = (new Email)
            ->from('hello@example.com')
            ->to('recipient@example.com')
            ->subject('test')
            ->html('<p>hi</p>');

        // The Resend transport stamps this header after the API call.
        $email->getHeaders()->addHeader('X-Resend-Email-ID', '11111111-2222-3333-4444-555555555555');

        $sent = new SentMessage($email, new Envelope(new Address('hello@example.com'), [new Address('recipient@example.com')]));

        // Symfony auto-generates this; it's what Mailer's MessageSent event
        // exposes as ->sent->getMessageId() when no transport overrides it.
        $symfonyAutoId = $sent->getMessageId();
        $this->assertNotSame('11111111-2222-3333-4444-555555555555', $symfonyAutoId);

        $event = new MessageSent(new LaravelSentMessage($sent), []);

        $listener = new class(app(Postmaster::class)) extends RecordOutboundMessage {
            public function exposedResolveProviderMessageId(MessageSent $event): ?string
            {
                return $this->resolveProviderMessageId($event);
            }
        };

        $this->assertSame(
            '11111111-2222-3333-4444-555555555555',
            $listener->exposedResolveProviderMessageId($event),
            'Bug: ResendTransport stamps the Resend id on X-Resend-Email-ID, not on SentMessage — without preferring the header, webhook correlation creates a phantom second row.'
        );
    }

    public function testProviderMessageIdPrefersSesHeaderOverSentMessageId()
    {
        // Same pattern: Laravel's SesTransport stamps the SES MessageId on
        // X-SES-Message-ID after the SES API call but leaves the SentMessage
        // with Symfony's auto-generated id. Without this header check,
        // delivery and bounce SNS notifications can't correlate.
        $email = (new Email)
            ->from('hello@example.com')
            ->to('r@example.com')
            ->subject('test')
            ->html('<p>hi</p>');

        $email->getHeaders()->addHeader('X-SES-Message-ID', '0100019e6b9004c8-2f5449de-4192-47ab-b688-c8fd7c993b98-000000');

        $sent = new SentMessage($email, new Envelope(new Address('hello@example.com'), [new Address('r@example.com')]));
        $event = new MessageSent(new LaravelSentMessage($sent), []);

        $listener = new class(app(Postmaster::class)) extends RecordOutboundMessage {
            public function exposedResolveProviderMessageId(MessageSent $event): ?string
            {
                return $this->resolveProviderMessageId($event);
            }
        };

        $this->assertSame(
            '0100019e6b9004c8-2f5449de-4192-47ab-b688-c8fd7c993b98-000000',
            $listener->exposedResolveProviderMessageId($event),
            'Bug: SesTransport stamps the id on X-SES-Message-ID, not on SentMessage — without preferring the header, SNS notifications create a phantom second row.'
        );
    }

    public function testProviderMessageIdFallsBackToSentMessageIdWhenHeaderAbsent()
    {
        $email = (new Email)
            ->from('hello@example.com')
            ->to('recipient@example.com')
            ->subject('test')
            ->html('<p>hi</p>');

        $sent = new SentMessage($email, new Envelope(new Address('hello@example.com'), [new Address('recipient@example.com')]));
        $event = new MessageSent(new LaravelSentMessage($sent), []);

        $listener = new class(app(Postmaster::class)) extends RecordOutboundMessage {
            public function exposedResolveProviderMessageId(MessageSent $event): ?string
            {
                return $this->resolveProviderMessageId($event);
            }
        };

        // Every other provider's Symfony transport sets the SentMessage's
        // id from its API response, so falling back to it is the right
        // default.
        $this->assertSame($sent->getMessageId(), $listener->exposedResolveProviderMessageId($event));
    }
}
