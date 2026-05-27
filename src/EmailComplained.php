<?php

namespace STS\Postmaster;

/**
 * The targeted variant of EmailEvent fired only for STATUS_COMPLAINED — the
 * recipient marked the message as spam. Use this to auto-unsubscribe a user,
 * alert ops, or drop the address from future campaigns.
 */
class EmailComplained extends EmailEvent
{
}
