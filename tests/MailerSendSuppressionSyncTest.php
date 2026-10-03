<?php

namespace STS\Postmaster\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use STS\Postmaster\Providers\MailerSend\SuppressionSync;
use UnexpectedValueException;

/**
 * Response shapes come from MailerSend's API docs. The paging links come
 * from its Go SDK and CLI; the docs' examples show only "data".
 */
class MailerSendSuppressionSyncTest extends TestCase
{
    protected const API = 'https://api.mailersend.com/v1/suppressions';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function sync(array $config = []): SuppressionSync
    {
        return new SuppressionSync(array_replace(['api_key' => 'api-key', 'domain_id' => null], $config));
    }

    protected function recipient(string $id, string $email, array $extra = []): array
    {
        return ['id' => $id, 'created_at' => '2026-09-20T12:00:00.123000Z', 'recipient' => ['id' => "r$id", 'email' => $email]] + $extra;
    }

    protected function page(array $rows, ?string $next = null): array
    {
        return ['data' => $rows, 'links' => ['next' => $next], 'meta' => ['current_page' => 1]];
    }

    /** One page per list, empty unless given. */
    protected function lists(array $pages = []): void
    {
        $responses = [];
        foreach (['hard-bounces', 'spam-complaints', 'unsubscribes', 'blocklist'] as $list) {
            $responses[self::API."/$list*"] = Http::response($pages[$list] ?? $this->page([]));
        }
        Http::fake($responses);
    }

    public function testRequiresAnApiKey(): void
    {
        $this->assertTrue($this->sync()->isAvailable());
        $this->assertFalse($this->sync(['api_key' => null])->isAvailable());
        $this->assertFalse($this->sync(['api_key' => ''])->isAvailable());
        Http::assertNothingSent();
    }

    public function testPullsEveryListWithItsReason(): void
    {
        $this->lists([
            'hard-bounces' => $this->page([$this->recipient('1', 'BOUNCE@example.com', ['reason' => 'Unknown reason'])]),
            'spam-complaints' => $this->page([$this->recipient('2', 'complaint@example.com')]),
            'unsubscribes' => $this->page([$this->recipient('3', 'unsubscribe@example.com', ['reason' => 'NEVER_SIGNED'])]),
            'blocklist' => $this->page([
                ['id' => '4', 'type' => 'exact', 'pattern' => 'blocked@example.com', 'created_at' => '2026-09-20T12:00:00.000000Z'],
                ['id' => '5', 'type' => 'pattern', 'pattern' => '.*@example.net', 'created_at' => '2026-09-20T12:00:00.000000Z'],
            ]),
        ]);

        $rows = iterator_to_array($this->sync()->pull(), false);

        $this->assertSame(['bounce@example.com', 'complaint@example.com', 'unsubscribe@example.com', 'blocked@example.com'], array_column($rows, 'address'));
        $this->assertSame(['bounced', 'complained', 'unsubscribed', 'manual'], array_column($rows, 'reason'));
        $this->assertSame('2026-09-20 12:00:00.123000', $rows[0]['suppressed_at']->format('Y-m-d H:i:s.u'));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer api-key') && $request['limit'] === 100);
    }

    public function testFollowsTheNextLinkUntilTheLastPage(): void
    {
        Http::fake([
            self::API.'/hard-bounces*' => Http::sequence()
                ->push($this->page([$this->recipient('1', 'one@example.com')], self::API.'/hard-bounces?page=2'))
                ->push($this->page([$this->recipient('2', 'two@example.com')])),
            self::API.'/*' => Http::response($this->page([])),
        ]);

        $rows = iterator_to_array($this->sync()->pull(), false);

        $this->assertSame(['one@example.com', 'two@example.com'], array_column($rows, 'address'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'hard-bounces') && $request['page'] === 2);
    }

    public function testKeepsPagingAFullPageWhenLinksAreMissing(): void
    {
        // If MailerSend leaves out links, a full page may not be the last.
        $full = array_map(fn ($i) => $this->recipient("$i", "user$i@example.com"), range(1, 100));
        Http::fake([
            self::API.'/hard-bounces*' => Http::sequence()->push(['data' => $full])->push(['data' => [$this->recipient('101', 'last@example.com')]]),
            self::API.'/*' => Http::response(['data' => []]),
        ]);

        $this->assertCount(101, iterator_to_array($this->sync()->pull(), false));
    }

    public function testScopesEveryRequestToTheConfiguredDomain(): void
    {
        $this->lists();
        iterator_to_array($this->sync(['domain_id' => 'yv69oxl5kl785kw2'])->pull(), false);

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request) => $request['domain_id'] === 'yv69oxl5kl785kw2');
        Http::assertNotSent(fn (Request $request) => ($request['domain_id'] ?? null) !== 'yv69oxl5kl785kw2');
    }

    public function testFailsRatherThanReturningAPartialList(): void
    {
        // A short list would clear local suppressions, so any doubt throws.
        foreach ([Http::response(['message' => 'Unauthenticated.'], 401), Http::response(['nope' => []]), Http::response('<html>')] as $response) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake([self::API.'/*' => $response]);

            try {
                iterator_to_array($this->sync()->pull(), false);
                $this->fail('Expected the pull to fail.');
            } catch (RequestException|UnexpectedValueException $e) {
                $this->assertTrue(true);
            }
        }

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([self::API.'/*' => Http::response($this->page([['id' => '1', 'recipient' => ['email' => '']]]))]);
        $this->expectException(UnexpectedValueException::class);
        iterator_to_array($this->sync()->pull(), false);
    }

    public function testUnsuppressDeletesTheMatchingEntryFromEachList(): void
    {
        // MailerSend deletes by entry id, not by address.
        Http::fake(function (Request $request) {
            if ($request->method() === 'DELETE') {
                return Http::response('', 200);
            }

            return Http::response(match (true) {
                str_contains($request->url(), 'hard-bounces') => $this->page([$this->recipient('b1', 'other@example.com'), $this->recipient('b2', 'User@Example.com')]),
                str_contains($request->url(), 'blocklist') => $this->page([['id' => 'k1', 'type' => 'exact', 'pattern' => 'user@example.com']]),
                default => $this->page([]),
            });
        });

        $this->assertTrue($this->sync(['domain_id' => 'dom'])->unsuppress('user@example.com'));

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === self::API.'/hard-bounces' && $request['ids'] === ['b2'] && $request['domain_id'] === 'dom');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === self::API.'/blocklist' && $request['ids'] === ['k1']);
        $this->assertCount(2, Http::recorded(fn (Request $request) => $request->method() === 'DELETE'));
    }

    public function testUnsuppressReportsAnAddressThatIsNotListed(): void
    {
        $this->lists();
        $this->assertFalse($this->sync()->unsuppress('user@example.com'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function testUnsuppressThrowsWhenMailerSendRefuses(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response(['message' => 'The given data was invalid.'], 422)
            : Http::response($this->page(str_contains($request->url(), 'hard-bounces') ? [$this->recipient('b1', 'user@example.com')] : [])));

        $this->expectException(RequestException::class);
        $this->sync()->unsuppress('user@example.com');
    }
}
