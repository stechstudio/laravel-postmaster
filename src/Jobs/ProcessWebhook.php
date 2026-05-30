<?php

namespace STS\Postmaster\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use STS\Postmaster\Postmaster;

/**
 * Adapts a raw provider webhook payload into EmailEvents and dispatches them,
 * off the request cycle. Spawned by WebhookController when
 * postmaster.queue_webhooks is on; otherwise the controller does the same
 * work synchronously and this job is never enqueued.
 *
 * Only the provider name and raw payload travel on the queue — the Adapter
 * and EmailEvent are reconstructed inside handle(), so the payload that's
 * serialized is just an array (no models, no closures, no resources).
 */
class ProcessWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $payload,
    ) {
        $this->onConnection(config('postmaster.queue_connection'))
             ->onQueue(config('postmaster.queue_name'));
    }

    public function handle(Postmaster $postmaster): void
    {
        $postmaster->provider($this->provider)
            ->adapt($this->payload)
            ->dispatch();
    }
}
