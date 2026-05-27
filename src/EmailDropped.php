<?php

namespace STS\Postmaster;

/**
 * The targeted variant of EmailEvent fired only for STATUS_DROPPED — the
 * provider rejected the send before any delivery attempt (suppression list,
 * spam-filter pre-screen, invalid syntax, etc.). Address is auto-suppressed
 * since dropped events are treated as permanent failures.
 */
class EmailDropped extends EmailEvent
{
}
