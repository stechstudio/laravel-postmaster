<?php

namespace STS\Postmaster\Tests;

use Postmark\Models\Suppressions\PostmarkSuppression;
use Postmark\Models\Suppressions\PostmarkSuppressionList;
use Postmark\Models\Suppressions\PostmarkSuppressionRequestResult;
use Postmark\Models\Suppressions\PostmarkSuppressionResultList;
use Postmark\PostmarkClient;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Providers\Postmark\SuppressionSync;

/**
 * Regression coverage for three bugs found running the real Postmark SDK
 * end-to-end. All three made postmaster:sync and Postmaster::unsuppress()
 * fail against a real account while the FakeSync-backed tests kept passing.
 *
 * The SDK is loaded as require-dev so we can exercise its actual response
 * shapes here without making real HTTP calls.
 */
class PostmarkSuppressionSyncTest extends TestCase
{
    public function testPullReadsSuppressionsViaTheAccessorNotPropertyAccess()
    {
        // The SDK's PostmarkSuppressionList declares Suppressions as
        // protected. Property access on $response->Suppressions returns
        // null, silently null-coalescing to []. We must use getSuppressions().
        $list = new PostmarkSuppressionList([
            'Suppressions' => [
                [
                    'EmailAddress'      => 'alice@example.com',
                    'SuppressionReason' => 'HardBounce',
                    'Origin'            => 'Recipient',
                    'CreatedAt'         => '2026-01-01T00:00:00-05:00',
                ],
                [
                    'EmailAddress'      => 'bob@example.com',
                    'SuppressionReason' => 'SpamComplaint',
                    'Origin'            => 'Recipient',
                    'CreatedAt'         => '2026-01-02T00:00:00-05:00',
                ],
            ],
        ]);

        $client = $this->createStub(PostmarkClient::class);
        $client->method('getSuppressions')->willReturn($list);

        $sync = new class(['server_token' => 'x'], $client) extends SuppressionSync {
            public function __construct(array $config, public PostmarkClient $injected)
            {
                parent::__construct($config);
            }
            protected function client(): PostmarkClient
            {
                return $this->injected;
            }
        };

        $entries = iterator_to_array($sync->pull(), false);

        $this->assertCount(2, $entries, 'Bug: pull() reads protected $Suppressions as a property — should call getSuppressions()');
        $this->assertSame('alice@example.com', $entries[0]['address']);
        $this->assertSame(EmailAddress::REASON_BOUNCED, $entries[0]['reason']);
        $this->assertSame('bob@example.com', $entries[1]['address']);
        $this->assertSame(EmailAddress::REASON_COMPLAINED, $entries[1]['reason']);
    }

    public function testUnsuppressCallsDeleteSuppressionsWithEntriesFirstStreamSecond()
    {
        // The SDK's deleteSuppressions signature is
        //   (array $suppressionChanges, ?string $messageStream)
        // — entries first, stream second. The previous code reversed them
        // and crashed with TypeError. Also: each entry must be an
        // ['EmailAddress' => '...'] array, not a bare string.
        $captured = ['args' => null];

        $client = $this->createStub(PostmarkClient::class);
        $client->method('deleteSuppressions')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured['args'] = $args;

                return new PostmarkSuppressionResultList([
                    'Suppressions' => [
                        ['EmailAddress' => 'alice@example.com', 'Status' => 'Deleted', 'Message' => ''],
                    ],
                ]);
            });

        $sync = new class(['server_token' => 'x'], $client) extends SuppressionSync {
            public function __construct(array $config, public PostmarkClient $injected)
            {
                parent::__construct($config);
            }
            protected function client(): PostmarkClient
            {
                return $this->injected;
            }
        };

        $result = $sync->unsuppress('Alice@Example.com');

        $this->assertTrue($result);
        $this->assertSame(
            [['EmailAddress' => 'alice@example.com']],
            $captured['args'][0],
            'Bug: first argument must be the entries array, not the stream'
        );
        $this->assertSame('outbound', $captured['args'][1]);
    }

    public function testUnsuppressTreatsAnEmptySuppressionsResponseAsSuccess()
    {
        // Postmark is idempotent: deleting an already-cleared address
        // returns 200 with an empty Suppressions list. The previous code
        // returned false in this case; we treat no-failure-entries as success.
        $client = $this->createStub(PostmarkClient::class);
        $client->method('deleteSuppressions')->willReturn(
            new PostmarkSuppressionResultList(['Suppressions' => []])
        );

        $sync = new class(['server_token' => 'x'], $client) extends SuppressionSync {
            public function __construct(array $config, public PostmarkClient $injected)
            {
                parent::__construct($config);
            }
            protected function client(): PostmarkClient
            {
                return $this->injected;
            }
        };

        $this->assertTrue(
            $sync->unsuppress('alice@example.com'),
            'Bug: an empty Suppressions response means "nothing to delete" (already cleared), not failure'
        );
    }

    public function testUnsuppressReturnsFalseWhenPostmarkReportsFailedStatus()
    {
        // Negative side of the rule above: a Failed status on the entry
        // really is a failure.
        $client = $this->createStub(PostmarkClient::class);
        $client->method('deleteSuppressions')->willReturn(
            new PostmarkSuppressionResultList([
                'Suppressions' => [
                    ['EmailAddress' => 'alice@example.com', 'Status' => 'Failed', 'Message' => 'why'],
                ],
            ])
        );

        $sync = new class(['server_token' => 'x'], $client) extends SuppressionSync {
            public function __construct(array $config, public PostmarkClient $injected)
            {
                parent::__construct($config);
            }
            protected function client(): PostmarkClient
            {
                return $this->injected;
            }
        };

        $this->assertFalse($sync->unsuppress('alice@example.com'));
    }
}
