<?php

namespace STS\Postmaster\Providers\Helo;

use Illuminate\Http\Client\HttpClientException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Message;
use UnexpectedValueException;

class Transport extends AbstractTransport
{
    public const RESULT_HEADER = 'X-Postmaster-Helo-Result';

    public function __construct(protected Client $client, protected string $mailType = 'transactional')
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            throw new TransportException('Helo requires a MIME message.');
        }
        $email = MessageConverter::toEmail($original);
        $payload = $this->payload($email, $message->getEnvelope());
        $idempotencyKey = $email->getHeaders()->get('X-Helo-Idempotency-Key')?->getBodyAsString();

        try {
            $result = $this->client->send($payload, $this->mailType, $idempotencyKey);
        } catch (HttpClientException|UnexpectedValueException $exception) {
            // Symfony's failover transport recognizes TransportException.
            throw new TransportException('Helo send failed: '.$exception->getMessage(), 0, $exception);
        }

        $message->setMessageId($result['messageId']);

        // Added only after the API call. The listener records per-recipient
        // outcomes, including recipients Helo suppressed during submission.
        $email->getHeaders()->remove(self::RESULT_HEADER);
        $email->getHeaders()->addTextHeader(self::RESULT_HEADER, json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    protected function payload(Email $email, Envelope $envelope): array
    {
        // Prepare inline attachments first, so Symfony resolves cid:name to
        // the same Content-ID we send in the attachment object.
        $email = MessageConverter::toEmail(new Message($email->getHeaders(), $email->getBody()));
        // Send each envelope recipient once, in its most visible header role.
        // Recipients missing from every header (a custom envelope) go to To.
        $listed = fn (array $addresses) => array_map(fn (Address $address) => strtolower($address->getAddress()), $addresses);
        [$toHeader, $ccHeader] = [$listed($email->getTo()), $listed($email->getCc())];
        $bccHeader = $listed($email->getBcc());
        $roles = ['to' => [], 'cc' => [], 'bcc' => []];
        foreach ($envelope->getRecipients() as $recipient) {
            $key = strtolower($recipient->getAddress());
            $role = match (true) {
                in_array($key, $toHeader, true) => 'to',
                in_array($key, $ccHeader, true) => 'cc',
                in_array($key, $bccHeader, true) => 'bcc',
                default => 'to',
            };
            $roles[$role][$key] ??= $this->address($recipient);
        }

        $payload = [
            'from' => $this->address($email->getFrom()[0] ?? $envelope->getSender()),
            'to' => array_values($roles['to']),
            'cc' => array_values($roles['cc']),
            'bcc' => array_values($roles['bcc']),
            'replyTo' => array_map($this->address(...), $email->getReplyTo()),
            'subject' => $email->getSubject(),
            'html' => $this->body($email->getHtmlBody()),
            'text' => $this->body($email->getTextBody()),
        ];

        foreach ($email->getAttachments() as $attachment) {
            $item = [
                'fileName' => $attachment->getFilename() ?? 'attachment',
                'contentType' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'content' => base64_encode($attachment->getBody()),
                'disposition' => $attachment->getDisposition(),
            ];
            if ($attachment->getDisposition() === 'inline') {
                $item['contentId'] = $attachment->getContentId();
            }
            $payload['attachments'][] = $item;
        }

        $skip = ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type', 'content-transfer-encoding', 'mime-version', 'x-helo-idempotency-key'];
        // Postmaster stamps a fresh Message-ID on every attempt, which would
        // make a retry a different request and Helo would reject the key.
        if ($email->getHeaders()->has('X-Helo-Idempotency-Key')) {
            $skip[] = 'message-id';
        }
        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());
            if ($header instanceof TagHeader) {
                $payload['tags'][] = $header->getBodyAsString();
            } elseif ($header instanceof MetadataHeader) {
                $payload['metadata'][$header->getKey()] = $header->getBodyAsString();
            } elseif (in_array($name, ['x-helo-trackopens', 'x-helo-tracklinks'], true)) {
                $key = $name === 'x-helo-trackopens' ? 'opens' : 'links';
                $payload['tracking'][$key] = filter_var($header->getBodyAsString(), FILTER_VALIDATE_BOOL);
            } elseif (! in_array($name, $skip, true) && ! str_starts_with($name, 'x-postmaster-')) {
                $payload['headers'][$header->getName()] = $header->getBodyAsString();
            }
        }

        return array_filter($payload, fn ($value, $key) => $key === 'to' || ($value !== null && $value !== []), ARRAY_FILTER_USE_BOTH);
    }

    /** @return array{email: string, name: string} */
    protected function address(Address $address): array
    {
        return ['email' => $address->getAddress(), 'name' => $address->getName()];
    }

    /** @param resource|string|null $body */
    protected function body($body): ?string
    {
        if (is_resource($body)) {
            rewind($body);
            return stream_get_contents($body);
        }

        return $body;
    }

    public function __toString(): string
    {
        return 'helo';
    }
}
