<?php

namespace STS\Postmaster\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\Providers\Helo\Client;
use STS\Postmaster\Providers\Helo\Transport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class HeloTransportTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        // Some application providers resolve mail before package boot.
        $app->make('mail.manager');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function email(): Email
    {
        return (new Email)->from(new Address('sender@example.com', 'Sender'))->to('to@example.com')
            ->cc('cc@example.com')->bcc('bcc@example.com')->replyTo('reply@example.com')
            ->subject('Receipt')->html('<p>Hello</p>')->text('Hello');
    }

    public function testSendsRecipientsAttachmentsHeadersTagsAndMetadata(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);
        $email = $this->email()->attach("\x00\x01PDF", 'receipt.pdf', 'application/pdf');
        $email->getHeaders()->add(new TagHeader('receipt'));
        $email->getHeaders()->add(new TagHeader('order'));
        $email->getHeaders()->add(new MetadataHeader('order_id', '42'));
        $email->getHeaders()->addTextHeader('X-Custom', 'keep');
        $email->getHeaders()->addTextHeader('X-Postmaster-Tenant', 'secret');
        $email->getHeaders()->addTextHeader('X-Helo-Idempotency-Key', 'request-42');
        $email->getHeaders()->addTextHeader('X-Helo-TrackOpens', 'true');
        $email->getHeaders()->addTextHeader('X-Helo-TrackLinks', 'false');
        $sent = (new Transport(new Client('api-key', 'channel-id')))->send($email);
        $this->assertSame('helo-id', $sent->getMessageId());
        Http::assertSent(function ($request) {
            $data = $request->data();
            $this->assertSame(['email' => 'sender@example.com', 'name' => 'Sender'], $data['from']);
            foreach (['to', 'cc', 'bcc'] as $role) {
                $this->assertSame($role.'@example.com', $data[$role][0]['email']);
            }
            $this->assertSame('reply@example.com', $data['replyTo'][0]['email']);
            $this->assertSame(['receipt', 'order'], $data['tags']);
            $this->assertSame(['order_id' => '42'], $data['metadata']);
            $this->assertSame(['opens' => true, 'links' => false], $data['tracking']);
            $this->assertSame(base64_encode("\x00\x01PDF"), $data['attachments'][0]['content']);
            $this->assertSame('attachment', $data['attachments'][0]['disposition']);
            $this->assertArrayNotHasKey('Bcc', $data['headers']);
            $this->assertArrayNotHasKey('X-Postmaster-Tenant', $data['headers']);
            $this->assertArrayNotHasKey('X-Metadata-order_id', $data['headers']);
            return $request->url() === 'https://api.helohq.com/send/transactional'
                && $request->hasHeader('Authorization', 'Bearer api-key')
                && $request->hasHeader('X-Helo-Channel-Id', 'channel-id')
                && $request->hasHeader('X-Helo-Idempotency-Key', 'request-42');
        });
    }

    public function testPreservesInlineContentIdsAndStreamBodies(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'stream body');
        $email = $this->email()->text($stream)->html('<img src="cid:logo.png">')->embed('image bytes', 'logo.png', 'image/png');
        (new Transport(new Client('api-key')))->send($email);
        Http::assertSent(function ($request) {
            $inline = $request['attachments'][0];
            return $request['html'] === '<img src="cid:'.$inline['contentId'].'">'
                && $inline['disposition'] === 'inline' && $inline['content'] === base64_encode('image bytes')
                && $request['text'] === 'stream body' && ! $request->hasHeader('X-Helo-Channel-Id');
        });
        fclose($stream);
    }

    public function testUsesTheEnvelopeToAvoidLeakingOriginalRecipientsOnRedirect(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);
        (new Transport(new Client('api-key')))->send($this->email(), new Envelope(new Address('sender@example.com'), [new Address('safe@example.com')]));
        Http::assertSent(fn ($request) => $request['to'][0]['email'] === 'safe@example.com' && ! isset($request['cc']) && ! isset($request['bcc']));
    }

    public function testPreservesTheRequiredToFieldForABccOnlyMessage(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);
        $email = (new Email)->from('sender@example.com')->bcc('blind@example.com')->text('Private');
        (new Transport(new Client('api-key')))->send($email);
        Http::assertSent(fn ($request) => $request['to'] === [] && $request['bcc'][0]['email'] === 'blind@example.com');
    }

    public function testRegistersTheLaravelMailerAndBroadcastTransport(): void
    {
        config(['mail.mailers.helo' => ['transport' => 'helo', 'key' => 'mail-key', 'channel_id' => 'channel-id', 'mail_type' => 'broadcast']]);
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);
        $transport = Mail::mailer('helo')->getSymfonyTransport();
        $this->assertInstanceOf(Transport::class, $transport);
        $this->assertSame('helo', (string) $transport);
        $transport->send($this->email());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.helohq.com/send/broadcast/message' && $request->hasHeader('Authorization', 'Bearer mail-key'));
    }

    public function testFailsOnHttpErrorsApiRejectionsAndMalformedResponses(): void
    {
        foreach ([[['message' => 'unauthorized'], 401], [['message' => 'limited'], 429], [['message' => 'unavailable'], 500], [['status' => 'failed', 'errorCode' => 'recipients_suppressed'], 200], [['status' => 'accepted'], 200], [null, 200]] as [$body, $status]) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(['api.helohq.com/*' => Http::response($body, $status)]);
            try {
                (new Transport(new Client('api-key')))->send($this->email());
                $this->fail('Expected a transport failure.');
            } catch (TransportException $exception) {
                $this->assertStringContainsString('Helo send failed', $exception->getMessage());
            }
            Http::assertSentCount(1);
        }
    }

    public function testReportsHelosReasonWhenEveryRecipientIsSuppressed(): void
    {
        // Captured from the live API.
        Http::fake(['api.helohq.com/*' => Http::response([
            'title' => 'Send request failed', 'status' => 422,
            'code' => 'recipients_suppressed', 'detail' => 'All recipients are suppressed.',
        ], 422)]);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Helo send failed: Helo API error 422 (recipients_suppressed): All recipients are suppressed.');
        (new Transport(new Client('api-key')))->send($this->email());
    }

    public function testRetriesWithAnIdempotencyKeySendTheSameRequest(): void
    {
        // Helo answers a replayed key with PascalCase keys (captured live).
        Http::fakeSequence('api.helohq.com/*')
            ->push(['status' => 'accepted', 'messageId' => 'helo-id'])
            ->push(['Status' => 'accepted', 'MessageId' => 'helo-id']);
        $transport = new Transport(new Client('api-key'));

        foreach ([1, 2] as $attempt) {
            // Postmaster stamps a fresh Message-ID on every attempt.
            $email = $this->email();
            $email->getHeaders()->addIdHeader('Message-ID', "attempt-{$attempt}@example.com");
            $email->getHeaders()->addTextHeader('X-Helo-Idempotency-Key', 'order-42');
            $this->assertSame('helo-id', $transport->send($email)->getMessageId());
        }

        $bodies = Http::recorded()->map(fn ($pair) => $pair[0]->body())->unique();
        $this->assertCount(1, $bodies);
        $this->assertStringNotContainsString('attempt-', $bodies->first());
    }

    public function testForwardsMessageIdWithoutAnIdempotencyKey(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);
        $email = $this->email();
        $email->getHeaders()->addIdHeader('Message-ID', 'thread@example.com');
        (new Transport(new Client('api-key')))->send($email);
        Http::assertSent(fn ($request) => $request->data()['headers']['Message-ID'] === '<thread@example.com>');
    }

    public function testKeepsAnAddressListedInBothToAndBccAsADirectRecipient(): void
    {
        // Postmaster's resend of a bcc row produces this shape (seen live).
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'helo-id'])]);
        $email = (new Email)->from('sender@example.com')->to('bcc@example.com')->cc('cc@example.com')
            ->bcc('bcc@example.com')->subject('Again')->html('<p>Hi</p>');
        (new Transport(new Client('api-key')))->send($email);
        Http::assertSent(function ($request) {
            $data = $request->data();
            $this->assertSame(['bcc@example.com'], array_column($data['to'], 'email'));
            $this->assertSame(['cc@example.com'], array_column($data['cc'], 'email'));
            $this->assertArrayNotHasKey('bcc', $data);
            return true;
        });
    }

    public function testWrapsConnectionFailureForLaravelFailover(): void
    {
        Http::fake(['api.helohq.com/*' => Http::failedConnection()]);
        $this->expectException(TransportException::class);
        (new Transport(new Client('api-key')))->send($this->email());
    }
}
