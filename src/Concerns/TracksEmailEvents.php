<?php

namespace STS\Postmaster\Concerns;

use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\Postmaster;

/**
 * Associates an outbound email with one of your models (an Order, User, etc.)
 * and/or the tenant it belongs to. When persistence is enabled, the recorded
 * email_messages row is linked back accordingly, so a model can list its
 * delivery history and a tenant's email activity can be queried as a whole.
 *
 * Add it to a Mailable, or to a MailMessage subclass — the trait only depends
 * on withSymfonyMessage(), which both Laravel Mailables and
 * Illuminate\Notifications\Messages\MailMessage expose. For notifications, the
 * package ships STS\Postmaster\Notifications\MailMessage with this trait
 * already applied; return that from toMail() to get relatedTo()/forTenant()
 * with no extra wiring. (To skip subclassing entirely, call
 * Postmaster::relatedTo()/forTenant() and pass the result to
 * withSymfonyMessage() yourself — the trait delegates to those same builders.)
 *
 * The associations are carried on the message only in-process: each is
 * written as a header, then read and stripped before the email is
 * transmitted, so nothing about the related model or tenant is ever exposed
 * in the outbound email.
 *
 * Requires the optional persistence layer (POSTMASTER_PERSISTENCE=true).
 */
trait TracksEmailEvents
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
}
