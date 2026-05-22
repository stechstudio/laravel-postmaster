<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use STS\Postmaster\Concerns\TracksMailable;
use STS\Postmaster\Tracking;

/**
 * A Mailable that declares what it's about with a postmaster() method, the
 * way TracksMailable expects.
 */
class DeclaredMail extends Mailable
{
    use TracksMailable;

    /**
     * @param array<int, string> $labels
     */
    public function __construct(
        public ?Model $order = null,
        public mixed $account = null,
        public array $labels = [],
        public ?bool $store = null,
    ) {
    }

    public function postmaster(): Tracking
    {
        return new Tracking(
            related: $this->order,
            tenant: $this->account,
            tags: $this->labels,
            storeContent: $this->store,
        );
    }

    public function build()
    {
        return $this->subject('Declared')->html('<p>declared</p>');
    }
}
