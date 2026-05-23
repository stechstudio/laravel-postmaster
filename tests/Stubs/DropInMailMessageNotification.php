<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Notifications\Notification;
use STS\Postmaster\Notifications\TrackedMailMessage;

/**
 * Associates a notification email by returning Postmaster's drop-in
 * MailMessage from toMail() and calling the fluent relatedTo()/forTenant().
 */
class DropInMailMessageNotification extends Notification
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
        return (new TrackedMailMessage)
            ->subject('Order shipped')
            ->line('Your order has shipped.')
            ->relatedTo($this->order)
            ->forTenant(7);
    }
}
