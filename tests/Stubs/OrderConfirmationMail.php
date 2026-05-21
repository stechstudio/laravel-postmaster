<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Mail\Mailable;
use STS\Postmaster\Concerns\TracksMailable;

class OrderConfirmationMail extends Mailable
{
    use TracksMailable;

    public function __construct( public Order $order )
    {
    }

    public function build()
    {
        return $this->relatedTo($this->order)
            ->subject('Order confirmed')
            ->html('<p>Thanks for your order</p>');
    }
}
