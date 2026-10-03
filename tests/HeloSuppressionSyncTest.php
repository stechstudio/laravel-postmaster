<?php

namespace STS\Postmaster\Tests;

use Illuminate\Support\Facades\Http;
use STS\Postmaster\Providers\Helo\SuppressionSync;
use UnexpectedValueException;

class HeloSuppressionSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function sync(array $config = []): SuppressionSync
    {
        config(['helo.key' => array_key_exists('api_key', $config) ? $config['api_key'] : 'api-key']);
        config(['helo.channel_id' => array_key_exists('channel_id', $config) ? $config['channel_id'] : 'channel-id']);
        app()->forgetInstance(\STS\HeloEmail\HeloClient::class);

        return new SuppressionSync(['mail_type' => $config['mail_type'] ?? 'transactional']);
    }

    protected function row(string $email = 'USER@EXAMPLE.COM', string $reason = 'bounce'): array
    {
        return ['email' => $email, 'reason' => $reason, 'createdAt' => '2026-09-20T12:00:00.123456Z'];
    }

    public function testRequiresApiKeyAndExplicitChannelWithoutAnSdk(): void
    {
        $this->assertTrue($this->sync()->isAvailable());
        foreach ([['api_key' => null], ['channel_id' => null], ['mail_type' => 'invalid']] as $config) {
            $this->assertFalse($this->sync($config)->isAvailable());
        }
        Http::assertNothingSent();
    }

    public function testPaginatesAndNormalizesAllSuppressionReasons(): void
    {
        Http::fake(['api.helohq.com/suppressions*' => Http::sequence()
            ->push(['totalCount' => 4, 'results' => [$this->row(), $this->row('complaint@example.com', 'complaint')]])
            ->push(['totalCount' => 4, 'results' => [$this->row('unsubscribe@example.com', 'unsubscribe'), $this->row('manual@example.com', 'manual')]])]);
        $rows = iterator_to_array($this->sync()->pull());
        $this->assertSame(['bounced', 'complained', 'unsubscribed', 'manual'], array_column($rows, 'reason'));
        $this->assertSame('user@example.com', $rows[0]['address']);
        $this->assertSame('123456', $rows[0]['suppressed_at']->format('u'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['offset'] === 2 && $request['channelId'] === 'channel-id'
            && $request['mailType'] === 'transactional' && $request['limit'] === 500
            && $request->hasHeader('Authorization', 'Bearer api-key'));
    }

    public function testAcceptsAnEmptyList(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['totalCount' => 0, 'results' => []])]);
        $this->assertSame([], iterator_to_array($this->sync()->pull()));
        Http::assertSentCount(1);
    }

    public function testRejectsMalformedTruncatedOrUnknownData(): void
    {
        foreach ([[], ['totalCount' => 0], ['totalCount' => 2, 'results' => []], ['totalCount' => 1, 'results' => [[]]], ['totalCount' => 1, 'results' => [$this->row(reason: 'new-reason')]]] as $response) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(['api.helohq.com/*' => Http::response($response)]);
            try {
                iterator_to_array($this->sync()->pull());
                $this->fail('An invalid list must not clear local suppressions.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Helo', $exception->getMessage());
            }
        }
    }

    public function testPropagatesHttpErrorsWithHelosReason(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 403, 'code' => 'forbidden', 'detail' => 'User or API credential does not have adequate permission to perform that action.'], 403)]);
        $this->expectException(\STS\HeloEmail\HeloException::class);
        $this->expectExceptionMessage('Helo API error 403 (forbidden): User or API credential does not have adequate permission');
        iterator_to_array($this->sync()->pull());
    }

    public function testRemovesOnlyTheConfiguredChannelAndMailType(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['results' => [['email' => 'user@example.com', 'success' => true]]])]);
        $this->assertTrue($this->sync(['mail_type' => 'broadcast'])->unsuppress('user@example.com'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.helohq.com/suppressions/remove'
            && $request->data() === ['channelId' => 'channel-id', 'mailType' => 'broadcast', 'emails' => ['user@example.com']]);
    }

    public function testDoesNotReportARejectedRemovalAsSuccessful(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['results' => [['email' => 'user@example.com', 'success' => false, 'message' => 'Cannot remove a complaint.']]])]);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Cannot remove a complaint.');
        $this->sync()->unsuppress('user@example.com');
    }

    public function testRejectsMissingRemovalResults(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['results' => []])]);
        $this->expectException(UnexpectedValueException::class);
        $this->sync()->unsuppress('user@example.com');
    }
}
