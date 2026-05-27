<?php

namespace STS\Postmaster;

/**
 * The targeted variant of EmailEvent fired only for STATUS_DELIVERED. Same
 * API as EmailEvent — extending it means the accessors, the correlated
 * EmailMessage, and every predicate are all there. Use this in place of
 *
 *     Event::listen(EmailEvent::class, fn ($e) => $e->isDelivered() && …)
 *
 * to skip the predicate entirely:
 *
 *     Event::listen(EmailDelivered::class, function (EmailDelivered $e) { … });
 */
class EmailDelivered extends EmailEvent
{
}
