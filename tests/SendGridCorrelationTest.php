<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\Providers\SendGrid\Adapter;

/**
 * Regression for the SendGrid correlation bug found in live testing.
 *
 * SendGrid webhooks deliver two ids:
 *   - `smtp-id` is the email's Message-ID header value as submitted via
 *     SMTP (with angle brackets). Symfony never writes that header to the
 *     Email object (it only stamps it on the wire), so the recorded send
 *     can't carry it for correlation.
 *   - `sg_message_id` is `<queue-id>.<filter-tags>`. The prefix is the
 *     SMTP queue id from SendGrid's 250 OK response — the value Symfony's
 *     SMTP transport stores on the SentMessage. Splitting at the first
 *     dot gives the right match.
 */
class SendGridCorrelationTest extends TestCase
{
    public function testProviderMessageIdReadsTheSgMessageIdPrefix()
    {
        $adapter = new Adapter([
            'event'         => 'delivered',
            'email'         => 'r@example.com',
            'timestamp'     => 1779922140,
            'sg_message_id' => 'lyzdBwrJTeW4HDn5b3CI3w.filterdrecv-c8f9b6c9d-pjz8w-18-67890ABC-1A.0',
            'smtp-id'       => '<not-used-for-correlation@email.example.com>',
        ]);

        $this->assertSame(
            'lyzdBwrJTeW4HDn5b3CI3w',
            $adapter->providerMessageId(),
            'Bug: extracted full sg_message_id or smtp-id instead of the queue-id prefix — would never match what Symfony stored.'
        );
    }

    public function testProviderMessageIdHandlesSgMessageIdWithoutADotSuffix()
    {
        // Defensive: some payloads might have a bare queue id with no
        // filter-tag suffix. strstr() with $before_needle returns false
        // when the needle isn't found; the adapter falls back to the full
        // string in that case.
        $adapter = new Adapter([
            'event'         => 'delivered',
            'email'         => 'r@example.com',
            'timestamp'     => 1779922140,
            'sg_message_id' => 'lyzdBwrJTeW4HDn5b3CI3w',
        ]);

        $this->assertSame('lyzdBwrJTeW4HDn5b3CI3w', $adapter->providerMessageId());
    }

    public function testProviderMessageIdIsNullWhenSgMessageIdIsAbsent()
    {
        $adapter = new Adapter([
            'event'     => 'delivered',
            'email'     => 'r@example.com',
            'timestamp' => 1779922140,
        ]);

        $this->assertNull($adapter->providerMessageId());
    }
}
