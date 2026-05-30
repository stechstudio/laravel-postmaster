<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use STS\Postmaster\Concerns\TracksMailable;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Tracking;

/**
 * App-side mailable that declares a resent_from relationship via Tracking —
 * the path for code that does its own resend assembly rather than going
 * through Postmaster::resend().
 */
class TrackingResentFromMail extends Mailable
{
    use TracksMailable;

    public function __construct(public EmailMessage $original)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: ['r@example.com'],
            subject: 'manual resend',
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>manual resend body</p>');
    }

    public function postmaster(): Tracking
    {
        return new Tracking(resentFrom: $this->original);
    }
}
