<?php

namespace STS\Postmaster\Concerns;

use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Postmaster;

/**
 * Adds the fluent relatedTo() / forRecipient() / forTenant() / storeContent()
 * / dontStoreContent() methods to whatever it's applied to, declaring what
 * the email is about and how it should be recorded. When persistence is
 * enabled, the recorded email_messages row reflects each of them.
 *
 * For notifications, the package ships STS\Postmaster\Notifications\TrackedMailMessage
 * with this trait already applied — return that from toMail() and chain the
 * methods, no extra wiring. Add the trait directly only if you maintain your
 * own MailMessage subclass. (To skip subclassing entirely, call the matching
 * Postmaster builders and pass the result to withSymfonyMessage() yourself —
 * the trait delegates to those.)
 *
 * For Mailables, use TracksMailable instead — it carries these same methods,
 * and additionally lets a Mailable declare everything up front through a
 * postmaster() method, the way it declares envelope() / content().
 *
 * Each declaration is carried on the message only in-process: written as a
 * header, then read and stripped before the email is transmitted, so none of
 * it is ever exposed in the outbound email.
 *
 * Requires the optional persistence layer (POSTMASTER_PERSISTENCE=true).
 */
trait WithTracking
{
    /**
     * Associate this email with the given model.
     */
    public function relatedTo(Model $model): static
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->relatedTo($model));
    }

    /**
     * Record the recipient of this email as the given model — typically the
     * User it is being sent to. Distinct from relatedTo(): the related model
     * is what the email is *about* (an Order); the recipient is *who* the
     * email is for. Applies to the primary To recipient on a multi-recipient
     * send. Takes precedence over the resolveRecipientUsing() resolver.
     */
    public function forRecipient(Model $model): static
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->forRecipient($model));
    }

    /**
     * Record the recipient model per envelope address — for sends that go
     * to multiple recipients where each maps to a different user. Map keys
     * are email addresses (case-insensitive); values are Model instances.
     * Addresses not in the map fall through to the global recipient
     * resolver.
     *
     * @param array<string, Model> $map
     */
    public function forRecipients(array $map): static
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->forRecipients($map));
    }

    /**
     * Associate this email with the given tenant. Use this when tenant
     * context is not available globally (e.g. inside a queued job) — it
     * takes precedence over the Postmaster::resolveTenantUsing() resolver.
     *
     * @param Model|int|string $tenant A tenant model or its key.
     */
    public function forTenant(Model|int|string $tenant): static
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->forTenant($tenant));
    }

    /**
     * Store this email's content, overriding the store_content setting.
     */
    public function storeContent(): static
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->storeContent(true));
    }

    /**
     * Skip storing this email's content, overriding the store_content
     * setting. Use it for messages that carry secrets a database shouldn't
     * keep — password resets, magic-login links, MFA codes.
     */
    public function dontStoreContent(): static
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->storeContent(false));
    }

    /**
     * Record this email as a resend of the given EmailMessage (or its id).
     * The new row's resent_from_id points back; the dashboard's chain card
     * walks the link. Postmaster::resend() and the dashboard Resend button
     * apply this automatically — use it directly when app code does its own
     * resend outside those paths.
     */
    public function resentFrom(EmailMessage|int $message): static
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->resentFrom($message));
    }
}
