<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use STS\Postmaster\Facades\Postmaster;

/**
 * Associates a notification email using the Postmaster factory helpers on a
 * plain Laravel MailMessage — the no-subclass path.
 */
class RelatedNotification extends Notification
{
    public function __construct( public Order $order )
    {
    }

    public function via( $notifiable )
    {
        return ['mail'];
    }

    public function toMail( $notifiable )
    {
        return (new MailMessage)
            ->subject('Order shipped')
            ->line('Your order has shipped.')
            ->withSymfonyMessage(Postmaster::relatedTo($this->order))
            ->withSymfonyMessage(Postmaster::forTenant(7));
    }
}
