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
     * @param Model|null            $related      The model this email is about.
     * @param Model|int|string|null $tenant       The owning tenant, or its key.
     * @param array<int, string>    $tags         Free-form labels for filtering
     *                                            and querying recorded mail.
     * @param bool|null             $storeContent Whether to store this email's
     *                                            content. null defers to the
     *                                            postmaster.persistence.store_content
     *                                            setting.
     */
    public function __construct(
        public readonly ?Model $related = null,
        public readonly Model|int|string|null $tenant = null,
        public readonly array $tags = [],
        public readonly ?bool $storeContent = null,
    ) {
    }
}
