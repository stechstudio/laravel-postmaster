<?php

namespace STS\Postmaster;

/**
 * The targeted variant of EmailEvent fired only for STATUS_BOUNCED.
 *
 *     Event::listen(EmailBounced::class, function (EmailBounced $e) {
 *         // $e->toAddress(), $e->bounceType(), $e->isPermanent() …
 *     });
 *
 * See EmailEvent for the full API; this class adds nothing but a name.
 */
class EmailBounced extends EmailEvent
{
}
