<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Mail\Mailable;
use STS\Postmaster\Concerns\TracksMailable;
use STS\Postmaster\Tracking;

/**
 * A Mailable that carries an attachment and can track it — either by chaining
 * storeAttachments() / dontStoreAttachments(), or by declaring the preference
 * on a Tracking object.
 */
class AttachedMail extends Mailable
{
    use TracksMailable;

    public function __construct(public ?bool $storeAttachments = null)
    {
    }

    public function postmaster(): ?Tracking
    {
        return $this->storeAttachments === null
            ? null
            : new Tracking(storeAttachments: $this->storeAttachments);
    }

    public function build()
    {
        return $this->from('sender@example.com')
            ->subject('With attachment')
            ->html('<p>Body</p>')
            ->attachData('PDF DATA', 'invoice.pdf', ['mime' => 'application/pdf']);
    }
}
