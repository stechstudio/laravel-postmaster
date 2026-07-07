<?php

namespace STS\Postmaster\Mail;

use Illuminate\Mail\Mailable;
use STS\Postmaster\Models\EmailMessage;

/**
 * Sends a previously *sandboxed* email for real. Used by the dashboard's
 * Release action: sandbox delivery recorded the message and stored its
 * content but never handed it to the transport, and an operator has now
 * chosen to let this specific one out.
 *
 * The bodies, recipients, subject, and tags all come from the recorded row,
 * so a release requires stored content. Attachments are not restored — the
 * package only keeps their filenames, never their bytes.
 *
 * Unlike a resend, a release does not create a new record: it carries a
 * release marker (X-Postmaster-Release-Of) that tells InterceptSandboxMail
 * to let the send through despite sandbox mode still being on, and tells
 * RecordOutboundMessage to reconcile the original sandboxed row(s) — flipping
 * them to "sent" with the real provider message id — rather than writing new
 * ones. Because the row is then no longer sandboxed, it cannot be released
 * again.
 */
class ReleasedMessage extends Mailable
{
    public function __construct(public EmailMessage $record)
    {
    }

    public function build(): static
    {
        if ($this->record->from_address) {
            $this->from($this->record->from_address);
        }

        $this->to($this->record->to_address);

        $recipients = $this->record->recipients ?? [];

        foreach ($recipients['cc'] ?? [] as $cc) {
            $this->cc($cc['address']);
        }

        foreach ($recipients['bcc'] ?? [] as $bcc) {
            $this->bcc($bcc['address']);
        }

        if ($this->record->subject) {
            $this->subject($this->record->subject);
        }

        if ($this->record->html_body) {
            $this->html($this->record->html_body);
        } elseif ($this->record->text_body) {
            // Plain-text-only original (e.g. sent via Mail::raw). Laravel's
            // Mailable::text() takes a view name, not a raw string, so wrap
            // the text as a <pre> HTML body to satisfy content validation;
            // the authoritative text part is set on the Symfony message in
            // restoreTextAlternative() so the recipient still gets a faithful
            // multipart/alternative message.
            $this->html('<pre style="font-family:monospace;white-space:pre-wrap;">'
                .e($this->record->text_body).'</pre>');
        }

        if ($this->record->text_body) {
            $this->withSymfonyMessage($this->restoreTextAlternative());
        }

        // Carry the original tags onto the real send — a release is the
        // genuine delivery of this message, so the provider should see the
        // same tags it would have on a normal send.
        foreach ((array) $this->record->tags as $tag) {
            $this->tag($tag);
        }

        return $this;
    }

    /**
     * Restore the original text alternative on the outgoing message, so a
     * message with both an html and text body is sent as a faithful
     * multipart/alternative rather than html only.
     *
     * The release itself is flagged out-of-band by Postmaster::release()
     * (see OutboundMetadata::releasing()), so nothing needs to travel on the
     * message to identify it as a release.
     */
    protected function restoreTextAlternative(): \Closure
    {
        $text = $this->record->text_body;

        return function ($message) use ($text) {
            $message->text($text);
        };
    }
}
