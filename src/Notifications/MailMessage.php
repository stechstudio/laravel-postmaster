<?php

namespace STS\Postmaster\Notifications;

use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;
use STS\Postmaster\Concerns\TracksMailMessage;

/**
 * A drop-in replacement for Laravel's notification MailMessage that carries the
 * TracksMailMessage trait, so a notification's toMail() can associate the email
 * with one of your models or a tenant using the fluent relatedTo()/forTenant()
 * methods — without having to reach for withSymfonyMessage() by hand.
 *
 * Use it exactly as you would Laravel's MailMessage; only the import changes:
 *
 *     use STS\Postmaster\Notifications\MailMessage;
 *
 *     public function toMail($notifiable)
 *     {
 *         return (new MailMessage)
 *             ->subject('Your order shipped')
 *             ->line('Your order is on its way.')
 *             ->relatedTo($this->order)
 *             ->forTenant($this->order->tenant);
 *     }
 *
 * Every notification builder method (line(), action(), …) works unchanged.
 */
class MailMessage extends BaseMailMessage
{
    use TracksMailMessage;
}
