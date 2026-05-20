<?php

namespace STS\EmailEvents\Tests;

use Illuminate\Support\Facades\Event;
use STS\EmailEvents\EmailEvent;
use STS\EmailEvents\Facades\EmailEvents;

class WebhookRouteTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('email-events.token', 'secret-token');
    }

    protected function defineRoutes($router)
    {
        EmailEvents::routes();
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

    public function testValidWebhookDispatchesEmailEvent()
    {
        Event::fake();

        $this->postJson('/.hooks/email-events/sendgrid?auth=secret-token', $this->sendgridPayload())
            ->assertOk();

        Event::assertDispatched(EmailEvent::class);
    }

    public function testWebhookWithBadTokenIsRejected()
    {
        Event::fake();

        $this->postJson('/.hooks/email-events/sendgrid?auth=wrong-token', $this->sendgridPayload())
            ->assertForbidden();

        Event::assertNotDispatched(EmailEvent::class);
    }

    public function testWebhookWithNoTokenIsRejected()
    {
        $this->postJson('/.hooks/email-events/sendgrid', $this->sendgridPayload())
            ->assertForbidden();
    }
}
