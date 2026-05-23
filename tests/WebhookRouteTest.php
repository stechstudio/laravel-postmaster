<?php

namespace STS\Postmaster\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use STS\Postmaster\EmailEvent;

class WebhookRouteTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('postmaster.token', 'secret-token');
        $app['config']->set('postmaster.providers.sendgrid.auth', 'token');
        $app['config']->set('postmaster.providers.ses.auth', 'token');
    }

    protected function sendgridPayload(): array
    {
        return [
            'email'         => 'recipient@example.com',
            'event'         => 'delivered',
            'timestamp'     => 1609459200,
            'smtp-id'       => '<message-id@example.com>',
            'sg_message_id' => 'sg-message-1',
        ];
    }

    public function testWebhookRouteIsRegisteredAutomatically()
    {
        $this->assertTrue(Route::has('webhook.postmaster'));
    }

    public function testValidWebhookDispatchesEmailEvent()
    {
        Event::fake();

        $this->postJson('/webhooks/postmaster/sendgrid?auth=secret-token', $this->sendgridPayload())
            ->assertOk();

        Event::assertDispatched(EmailEvent::class);
    }

    public function testQueuedWebhooksReturn202AndEnqueueAJob()
    {
        config(['postmaster.queue_webhooks' => true]);
        \Illuminate\Support\Facades\Queue::fake();
        Event::fake();

        $this->postJson('/webhooks/postmaster/sendgrid?auth=secret-token', $this->sendgridPayload())
            ->assertStatus(202);

        // Nothing dispatches inline; the job handles it on the queue.
        Event::assertNotDispatched(EmailEvent::class);
        \Illuminate\Support\Facades\Queue::assertPushed(
            \STS\Postmaster\Jobs\ProcessWebhook::class,
            fn ($job) => $job->provider === 'sendgrid'
                && $job->payload['email'] === 'recipient@example.com'
        );
    }

    public function testQueuedJobReproducesTheSynchronousDispatch()
    {
        config(['postmaster.queue_webhooks' => true]);
        Event::fake();

        // Pop the job off the queue and run it manually; the event it would
        // have dispatched at the worker shows up the same way.
        (new \STS\Postmaster\Jobs\ProcessWebhook('sendgrid', $this->sendgridPayload()))
            ->handle(app(\STS\Postmaster\Postmaster::class));

        Event::assertDispatched(EmailEvent::class);
    }

    public function testWebhookWithBadTokenIsRejected()
    {
        Event::fake();

        $this->postJson('/webhooks/postmaster/sendgrid?auth=wrong-token', $this->sendgridPayload())
            ->assertForbidden();

        Event::assertNotDispatched(EmailEvent::class);
    }

    public function testWebhookWithNoTokenIsRejected()
    {
        $this->postJson('/webhooks/postmaster/sendgrid', $this->sendgridPayload())
            ->assertForbidden();
    }

    public function testSnsNotificationDispatchesEmailEvent()
    {
        Event::fake();

        $body = json_encode([
            'Type'    => 'Notification',
            'Message' => json_encode([
                'eventType' => 'Delivery',
                'mail'      => [
                    'timestamp'   => '2021-01-01T00:00:00.000Z',
                    'messageId'   => 'ses-message-1',
                    'destination' => ['recipient@example.com'],
                ],
            ]),
        ]);

        $this->call('POST', '/webhooks/postmaster/ses?auth=secret-token', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], $body)->assertOk();

        Event::assertDispatched(EmailEvent::class);
    }

    public function testSnsSubscriptionConfirmationIsConfirmed()
    {
        Http::fake();

        $body = json_encode([
            'Type'         => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc',
        ]);

        $this->call('POST', '/webhooks/postmaster/ses?auth=secret-token', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], $body)->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'Action=ConfirmSubscription');
        });
    }
}
