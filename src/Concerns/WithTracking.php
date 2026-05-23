<?php

namespace STS\Postmaster\Concerns;

use Illuminate\Database\Eloquent\Model;
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
     *
     * @param Model $model
     *
     * @return $this
     */
    public function relatedTo( Model $model )
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->relatedTo($model));
    }

    /**
     * Record the recipient of this email as the given model — typically the
     * User it is being sent to. Distinct from relatedTo(): the related model
     * is what the email is *about* (an Order); the recipient is *who* the
     * email is for. Takes precedence over the resolveRecipientUsing() resolver.
     *
     * @param Model $model
     *
     * @return $this
     */
    public function forRecipient( Model $model )
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->forRecipient($model));
    }

    /**
     * Associate this email with the given tenant. Use this when tenant
     * context is not available globally (e.g. inside a queued job) — it
     * takes precedence over the Postmaster::resolveTenantUsing() resolver.
     *
     * @param Model|int|string $tenant A tenant model or its key.
     *
     * @return $this
     */
    public function forTenant( $tenant )
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->forTenant($tenant));
    }

    /**
     * Store this email's content, overriding the store_content setting.
     *
     * @return $this
     */
    public function storeContent()
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->storeContent(true));
    }

    /**
     * Skip storing this email's content, overriding the store_content
     * setting. Use it for messages that carry secrets a database shouldn't
     * keep — password resets, magic-login links, MFA codes.
     *
     * @return $this
     */
    public function dontStoreContent()
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->storeContent(false));
    }
}
