<?php

namespace STS\Postmaster\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use STS\Postmaster\Concerns\WithTracking;

/**
 * A drop-in replacement for Laravel's notification MailMessage that carries the
 * WithTracking trait, so a notification's toMail() can associate the email
 * with one of your models or a tenant using the fluent relatedTo() /
 * forRecipient() / forTenant() methods — without having to reach for
 * withSymfonyMessage() by hand.
 *
 * Use it the same way you'd use Laravel's MailMessage; only the import changes:
 *
 *     use STS\Postmaster\Notifications\TrackedMailMessage;
 *
 *     public function toMail($notifiable)
 *     {
 *         return (new TrackedMailMessage)
 *             ->subject('Your order shipped')
 *             ->line('Your order is on its way.')
 *             ->relatedTo($this->order)
 *             ->forRecipient($notifiable);
 *     }
 *
 * Every notification builder method (line(), action(), …) works unchanged.
 */
class TrackedMailMessage extends MailMessage
{
    use WithTracking;
}
