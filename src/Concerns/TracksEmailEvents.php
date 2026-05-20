<?php

namespace STS\EmailEvents\Concerns;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Mime\Email;
use STS\EmailEvents\Support\OutboundMetadata;

/**
 * Add to a Mailable to associate the email with one of your models (an
 * Order, User, etc.) and/or the tenant it belongs to. When persistence is
 * enabled, the recorded email_messages row is linked back accordingly, so
 * a model can list its delivery history and a tenant's email activity can
 * be queried as a whole.
 *
 * The associations are carried on the message only in-process: each is
 * written as a header, then read and stripped before the email is
 * transmitted, so nothing about the related model or tenant is ever
 * exposed in the outbound email.
 *
 * Requires the optional persistence layer (MAIL_EVENTS_PERSISTENCE=true).
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
        return $this->withSymfonyMessage(function (Email $message) use ($model) {
            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_RELATED_TYPE, $model->getMorphClass()
            );

            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_RELATED_ID, (string) $model->getKey()
            );
        });
    }

    /**
     * Associate this email with the given tenant. Use this when tenant
     * context is not available globally (e.g. inside a queued job) — it
     * takes precedence over the EmailEvents::resolveTenantUsing() resolver.
     *
     * @param Model|int|string $tenant A tenant model or its key.
     *
     * @return $this
     */
    public function forTenant( $tenant )
    {
        $key = $tenant instanceof Model ? $tenant->getKey() : $tenant;

        return $this->withSymfonyMessage(function (Email $message) use ($key) {
            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_TENANT, (string) $key
            );
        });
    }
}
