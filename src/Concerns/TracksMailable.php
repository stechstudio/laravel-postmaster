<?php

namespace STS\Postmaster\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * The trait for Mailables. Add it to a Mailable and declare what the email is
 * about, the Laravel way — as `related()` and `tenant()` methods, the same
 * shape as `envelope()` / `content()`:
 *
 *     class OrderShipped extends Mailable
 *     {
 *         use TracksMailable;
 *
 *         public function related(): Model     { return $this->order; }
 *         public function tenant(): mixed      { return $this->order->account_id; }
 *     }
 *
 * Postmaster reads those methods at send time and records the association —
 * after the job is dequeued, so it is queue-safe. Both are optional; declare
 * only what you need (the tenant often comes from resolveTenantUsing()).
 *
 * The imperative relatedTo() / forTenant() methods are still available for
 * cases where the association is only known dynamically.
 */
trait TracksMailable
{
    use TracksMailMessage;

    /**
     * Apply any declared associations, then hand off to the Mailable's own
     * send(). This runs post-dequeue for queued mail, so the association is
     * registered without a closure ever being serialized onto the queue.
     *
     * @param \Illuminate\Contracts\Mail\Mailer|\Illuminate\Contracts\Mail\Factory $mailer
     *
     * @return mixed
     */
    public function send( $mailer )
    {
        $this->applyDeclaredAssociations();

        return parent::send($mailer);
    }

    /**
     * Read the optional related()/tenant() declarations and apply them.
     *
     * @return void
     */
    protected function applyDeclaredAssociations()
    {
        if (method_exists($this, 'related') && ($related = $this->related()) instanceof Model) {
            $this->relatedTo($related);
        }

        if (method_exists($this, 'tenant') && ($tenant = $this->tenant()) !== null) {
            $this->forTenant($tenant);
        }
    }
}
