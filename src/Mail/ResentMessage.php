<?php

namespace STS\Postmaster\Mail;

use Illuminate\Mail\Mailable;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Support\OutboundMetadata;

/**
 * Replays a previously recorded email through the configured mailer. Used by
 * the dashboard's Resend action — typically to recover from a bounce after
 * the recipient has corrected their address.
 *
 * The bodies, recipients, and subject all come from the recorded row — a
 * resend therefore requires stored content. Attachments are not restored:
 * the package only keeps their filenames, never their bytes.
 *
 * Business context (related model, recipient model, tenant, tags) carries
 * over so the resend lives under the same person / record in the dashboard
 * as the original, plus a "resent" tag of its own so a support reviewer
 * can tell at a glance it was replayed, not freshly composed.
 */
class ResentMessage extends Mailable
{
    public function __construct(public EmailMessage $record)
    {
    }

    public function build()
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
            // Mailable::text() takes a view name, not a raw string, so we
            // satisfy its content-validation by wrapping the text as a
            // <pre> HTML body. The actual plain-text part is set on the
            // Symfony message below as the text alternative — so the
            // recipient still gets a faithful multipart/alternative
            // message with the original text intact.
            $this->html('<pre style="font-family:monospace;white-space:pre-wrap;">'
                .e($this->record->text_body).'</pre>');
        }

        $this->withSymfonyMessage($this->propagateContext());

        foreach ((array) $this->record->tags as $tag) {
            $this->tag($tag);
        }

        return $this->tag('resent');
    }

    /**
     * Carry over the original's text alternative and tracking context
     * (related, recipient, tenant) via the in-process courier headers
     * — the same channel a fresh send would use. Stripped from the wire by
     * StashOutboundMetadata before the message is transmitted.
     *
     * @return \Closure
     */
    protected function propagateContext()
    {
        $record = $this->record;
        $tenantColumn = config('postmaster.persistence.tenant_column', 'tenant_id');

        return function ($message) use ($record, $tenantColumn) {
            // Text alternative. Always set when text_body is present — the
            // multipart/alternative wrapper sends both the html and text
            // parts to the recipient, and the text part is the authoritative
            // version of a text-only original.
            if ($record->text_body) {
                $message->text($record->text_body);
            }

            $headers = $message->getHeaders();

            if ($record->related_type && $record->related_id !== null) {
                $headers->addTextHeader(OutboundMetadata::HEADER_RELATED_TYPE, $record->related_type);
                $headers->addTextHeader(OutboundMetadata::HEADER_RELATED_ID, (string) $record->related_id);
            }

            if ($record->recipient_type && $record->recipient_id !== null) {
                $headers->addTextHeader(OutboundMetadata::HEADER_RECIPIENT_TYPE, $record->recipient_type);
                $headers->addTextHeader(OutboundMetadata::HEADER_RECIPIENT_ID, (string) $record->recipient_id);
            }

            if ($record->{$tenantColumn} !== null) {
                $headers->addTextHeader(OutboundMetadata::HEADER_TENANT, (string) $record->{$tenantColumn});
            }

            // Link the new row back to the original for the dashboard's
            // chain card and EmailMessage::resentFrom() / resends() relations.
            $headers->addTextHeader(OutboundMetadata::HEADER_RESENT_FROM, (string) $record->getKey());
        };
    }
}
