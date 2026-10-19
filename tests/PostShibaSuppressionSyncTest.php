<?php

namespace STS\Postmaster\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use STS\Postmaster\Providers\PostShiba\SuppressionSync;
use UnexpectedValueException;

/**
 * Response shapes come from PostShiba's API docs: a plain list ordered by id,
 * paged with "after" and "limit".
 */
class PostShibaSuppressionSyncTest extends TestCase
{
    protected const API = 'https://app.postshiba.com/api/v1/teams/OjMnKv/suppressions';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function sync(array $config = []): SuppressionSync
    {
        return new SuppressionSync(array_replace(['api_key' => 'api-key', 'team_id' => 'OjMnKv'], $config));
    }

    protected function row(string $id, string $email, string $reason = 'bounce'): array
    {
        return ['id' => $id, 'email' => $email, 'reason' => $reason, 'tenant_id' => 'WbLcFd', 'tenant' => 'default', 'created_at' => '2026-08-31T12:30:00Z'];
    }

    public function testRequiresAnApiKeyAndTeam(): void
    {
        $this->assertTrue($this->sync()->isAvailable());
        $this->assertFalse($this->sync(['api_key' => null])->isAvailable());
        $this->assertFalse($this->sync(['api_key' => ''])->isAvailable());
        $this->assertFalse($this->sync(['team_id' => null])->isAvailable());
        Http::assertNothingSent();
    }

    public function testPullsTheListWithReasons(): void
    {
        Http::fake([self::API.'*' => Http::response(json_decode(file_get_contents(__DIR__.'/fixtures/postshiba/suppressions.json'), true))]);

        $rows = iterator_to_array($this->sync()->pull(), false);

        $this->assertSame(['blocked@example.com', 'bounce@example.com'], array_column($rows, 'address'));
        $this->assertSame(['manual', 'bounced'], array_column($rows, 'reason'));
        $this->assertSame('2026-08-31 12:30:00', $rows[1]['suppressed_at']->format('Y-m-d H:i:s'));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer api-key')
            && str_starts_with($request->url(), self::API.'?') && $request['limit'] === 1000 && ! isset($request['after']));
    }

    public function testMapsReasonsLoosely(): void
    {
        Http::fake([self::API.'*' => Http::response([
            $this->row('a', 'one@example.com', 'Spam report'),
            $this->row('b', 'two@example.com', 'spamreport'),
            $this->row('c', 'three@example.com', 'Unsubscribe'),
            $this->row('d', 'four@example.com', 'hard_bounce'),
            $this->row('e', 'five@example.com', 'something new'),
        ])]);

        $rows = iterator_to_array($this->sync()->pull(), false);

        $this->assertSame(['complained', 'complained', 'unsubscribed', 'bounced', 'manual'], array_column($rows, 'reason'));
    }

    public function testPagesAfterTheLastIdUntilAShortPage(): void
    {
        $full = array_map(fn ($i) => $this->row("id$i", "user$i@example.com"), range(1, 1000));
        Http::fake([self::API.'*' => Http::sequence()->push($full)->push([$this->row('id1001', 'last@example.com')])]);

        $this->assertCount(1001, iterator_to_array($this->sync()->pull(), false));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => ($request['after'] ?? null) === 'id1000');
    }

    public function testFailsRatherThanReturningAPartialList(): void
    {
        // A short list would clear local suppressions, so any doubt throws.
        foreach ([
            Http::response(['error' => 'Forbidden'], 403),
            Http::response(['data' => []]),
            Http::response('<html>'),
            Http::response([['id' => 'a', 'email' => '']]),
            Http::response([['email' => 'user@example.com']]),
        ] as $response) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([self::API.'*' => $response]);

            try {
                iterator_to_array($this->sync()->pull(), false);
                $this->fail('Expected the pull to fail.');
            } catch (RequestException|UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testUnsuppressDeletesByAddress(): void
    {
        Http::fake([self::API.'/bulk_delete' => Http::response(['deleted' => 1, 'more' => false])]);

        $this->assertTrue($this->sync()->unsuppress('user@example.com'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['emails'] === ['user@example.com']);
    }

    public function testUnsuppressReportsAnAddressThatIsNotListed(): void
    {
        Http::fake([self::API.'/bulk_delete' => Http::response(['deleted' => 0, 'more' => false])]);

        $this->assertFalse($this->sync()->unsuppress('user@example.com'));
    }

    public function testUnsuppressThrowsWhenPostShibaRefuses(): void
    {
        Http::fake([self::API.'/bulk_delete' => Http::response(['error' => 'Forbidden'], 403)]);

        $this->expectException(RequestException::class);
        $this->sync()->unsuppress('user@example.com');
    }
}
