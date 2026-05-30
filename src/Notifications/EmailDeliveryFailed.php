<?php

namespace STS\Postmaster\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use STS\Postmaster\EmailEvent;

/**
 * A drop-in notification for delivery-failure alerts. Wrap an EmailEvent and
 * send it to whoever needs to hear about a hard bounce, drop, or complaint:
 *
 *     Event::listen(function (EmailEvent $event) {
 *         if ($event->isPermanent()) {
 *             Notification::route('mail', config('ops.alerts_to'))
 *                 ->notify(new EmailDeliveryFailed($event));
 *         }
 *     });
 *
 * The default rendering is a short mail summarizing the failure (address,
 * status, bounce type, provider's reason). Subclass and override toMail()
 * to customize, or to add `database` / `slack` channels.
 */
class EmailDeliveryFailed extends Notification
{
    public function __construct(public EmailEvent $event)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $event = $this->event;
        $address = $event->toAddress() ?? 'unknown address';

        $message = (new MailMessage)
            ->error()
            ->subject("Email delivery failed: {$address}")
            ->line("An email to **{$address}** was reported as **{$event->status()}** by {$event->provider()}.");

        if ($bounce = $event->bounceType()) {
            $message->line("Bounce severity: {$bounce}".($event->isPermanent() ? ' (permanent).' : ' (transient).'));
        }

        if ($reason = $event->reason()) {
            $message->line("Reason reported by the provider: {$reason}");
        }

        if ($response = $event->response()) {
            $message->line("Diagnostic: {$response}");
        }

        if ($record = $event->emailMessage()) {
            $message->line("Subject of the original email: ".($record->subject ?: '(no subject)'));
        }

        return $message;
    }
}
