<?php

namespace STS\Postmaster;

use Illuminate\Database\Eloquent\Model;

/**
 * What a Mailable declares to Postmaster about itself: the model the email is
 * about, the tenant it belongs to, any tags, and whether to store its content.
 * Returned from a Mailable's postmaster() method. Every field is optional, so
 * declare only the ones that apply.
 */
class Tracking
{
    /**
     * @param Model|null              $related      The model this email is about
     *                                              — typically a business record
     *                                              (Order, Invoice, etc.).
     * @param Model|null              $recipient    The person the primary To
     *                                              recipient is, as a model
     *                                              (a User). Distinct from
     *                                              $related so "every email
     *                                              this user has received" is
     *                                              a direct query regardless
     *                                              of what business record
     *                                              the email was about. Falls
     *                                              back to Postmaster::resolveRecipientUsing()
     *                                              when omitted. For multi-To
     *                                              sends, use $recipients
     *                                              instead.
     * @param array<string, Model>|null $recipients Per-address recipient model
     *                                              map, keyed by email address
     *                                              (case-insensitive). For
     *                                              sends with multiple To /
     *                                              Cc / Bcc recipients where
     *                                              each maps to a different
     *                                              user. Addresses not in the
     *                                              map fall through to the
     *                                              resolver. Takes precedence
     *                                              over the singular $recipient.
     * @param Model|int|string|null   $tenant       The owning tenant, or its key.
     * @param array<int, string>      $tags         Free-form labels for filtering
     *                                              and querying recorded mail.
     * @param bool|null               $storeContent Whether to store this email's
     *                                              content. null defers to the
     *                                              postmaster.persistence.store_content
     *                                              setting.
     */
    public function __construct(
        public readonly ?Model $related = null,
        public readonly ?Model $recipient = null,
        public readonly ?array $recipients = null,
        public readonly Model|int|string|null $tenant = null,
        public readonly array $tags = [],
        public readonly ?bool $storeContent = null,
    ) {
    }
}
