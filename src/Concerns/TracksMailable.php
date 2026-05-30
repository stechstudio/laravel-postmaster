<?php

namespace STS\Postmaster\Concerns;

use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\Tracking;

/**
 * The trait for Mailables. Add it and declare what the email is about with a
 * postmaster() method that returns a Tracking object, the same way a Mailable
 * declares envelope() / content():
 *
 *     class OrderShipped extends Mailable
 *     {
 *         use TracksMailable;
 *
 *         public function postmaster(): Tracking
 *         {
 *             return new Tracking(
 *                 related: $this->order,
 *                 tenant: $this->order->account_id,
 *             );
 *         }
 *     }
 *
 * Postmaster reads postmaster() at send time and records the declarations,
 * after the job is dequeued, so it is queue-safe. Every Tracking field is
 * optional.
 *
 * The imperative relatedTo() / forTenant() / storeContent() / dontStoreContent()
 * methods are still available for cases where a value is only known at runtime.
 */
trait TracksMailable
{
    use WithTracking;

    /**
     * Apply anything the Mailable declared, then hand off to its own send().
     * This runs post-dequeue for queued mail, so the declarations are
     * registered without a closure ever being serialized onto the queue.
     *
     * @param \Illuminate\Contracts\Mail\Mailer|\Illuminate\Contracts\Mail\Factory $mailer
     *
     * @return mixed
     */
    public function send( $mailer )
    {
        $this->applyDeclaredTracking();

        return parent::send($mailer);
    }

    /**
     * Read the optional postmaster() declaration and apply each field.
     *
     * @return void
     */
    protected function applyDeclaredTracking()
    {
        if (! method_exists($this, 'postmaster')) {
            return;
        }

        $tracking = $this->postmaster();

        if (! $tracking instanceof Tracking) {
            return;
        }

        if ($tracking->related instanceof Model) {
            $this->relatedTo($tracking->related);
        }

        if ($tracking->recipient instanceof Model) {
            $this->forRecipient($tracking->recipient);
        }

        if (! empty($tracking->recipients)) {
            $this->forRecipients($tracking->recipients);
        }

        if ($tracking->tenant !== null) {
            $this->forTenant($tracking->tenant);
        }

        // Tags are declared on the Tracking but applied through Laravel's
        // own tag() — one tag concept, recorded and provider-forwarded alike.
        foreach ($tracking->tags as $tag) {
            $this->tag($tag);
        }

        if ($tracking->storeContent !== null) {
            $tracking->storeContent ? $this->storeContent() : $this->dontStoreContent();
        }

        if ($tracking->resentFrom !== null) {
            $this->resentFrom($tracking->resentFrom);
        }
    }
}
