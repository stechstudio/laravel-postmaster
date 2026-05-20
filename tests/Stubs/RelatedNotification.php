<?php

namespace STS\EmailEvents\Tests\Stubs;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use STS\EmailEvents\Facades\EmailEvents;

/**
 * Associates a notification email using the EmailEvents factory helpers on a
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
            ->withSymfonyMessage(EmailEvents::relatedTo($this->order))
            ->withSymfonyMessage(EmailEvents::forTenant(7));
    }
}
