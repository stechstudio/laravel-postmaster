<?php

namespace STS\EmailEvents\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use STS\EmailEvents\Concerns\TracksEmailEvents;

class TrackedMail extends Mailable
{
    use TracksEmailEvents;

    public function __construct( public ?Model $related = null, public mixed $tenant = null )
    {
    }

    public function build()
    {
        if ($this->related !== null) {
            $this->relatedTo($this->related);
        }

        if ($this->tenant !== null) {
            $this->forTenant($this->tenant);
        }

        return $this->subject('Tracked')->html('<p>tracked</p>');
    }
}
