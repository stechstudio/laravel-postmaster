<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use STS\Postmaster\Concerns\TracksMailable;

/**
 * A Mailable that declares its associations the Laravel way — as related()
 * and tenant() methods that TracksMailable reads at send time.
 */
class DeclaredMail extends Mailable
{
    use TracksMailable;

    public function __construct( public ?Model $order = null, public mixed $account = null )
    {
    }

    public function related(): ?Model
    {
        return $this->order;
    }

    public function tenant(): mixed
    {
        return $this->account;
    }

    public function build()
    {
        return $this->subject('Declared')->html('<p>declared</p>');
    }
}
