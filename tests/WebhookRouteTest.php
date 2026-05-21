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

        $this->postJson('/.hooks/postmaster/sendgrid?auth=secret-token', $this->sendgridPayload())
            ->assertOk();

        Event::assertDispatched(EmailEvent::class);
    }

    public function testWebhookWithBadTokenIsRejected()
    {
        Event::fake();

        $this->postJson('/.hooks/postmaster/sendgrid?auth=wrong-token', $this->sendgridPayload())
            ->assertForbidden();

        Event::assertNotDispatched(EmailEvent::class);
    }

    public function testWebhookWithNoTokenIsRejected()
    {
        $this->postJson('/.hooks/postmaster/sendgrid', $this->sendgridPayload())
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

        $this->call('POST', '/.hooks/postmaster/ses?auth=secret-token', [], [], [], [
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

        $this->call('POST', '/.hooks/postmaster/ses?auth=secret-token', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], $body)->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'Action=ConfirmSubscription');
        });
    }
}
