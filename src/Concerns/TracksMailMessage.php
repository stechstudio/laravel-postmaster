<?php

namespace STS\Postmaster\Concerns;

use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\Postmaster;

/**
 * Adds relatedTo() / forTenant() to a notification's MailMessage, associating
 * the outbound email with one of your models (an Order, User, etc.) and/or the
 * tenant it belongs to. When persistence is enabled, the recorded
 * email_messages row is linked back accordingly.
 *
 * For notifications, the package ships STS\Postmaster\Notifications\MailMessage
 * with this trait already applied — return that from toMail() and chain
 * relatedTo()/forTenant(), no extra wiring. Add the trait directly only if you
 * maintain your own MailMessage subclass. (To skip subclassing entirely, call
 * Postmaster::relatedTo()/forTenant() and pass the result to
 * withSymfonyMessage() yourself — the trait delegates to those same builders.)
 *
 * For Mailables, use TracksMailable instead — it carries these same methods and
 * additionally lets a Mailable declare related()/tenant() the way it declares
 * envelope()/content().
 *
 * The associations are carried on the message only in-process: each is
 * written as a header, then read and stripped before the email is
 * transmitted, so nothing about the related model or tenant is ever exposed
 * in the outbound email.
 *
 * Requires the optional persistence layer (POSTMASTER_PERSISTENCE=true).
 */
trait TracksMailMessage
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
