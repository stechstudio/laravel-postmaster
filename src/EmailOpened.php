<?php

namespace STS\Postmaster;

/**
 * The targeted variant of EmailEvent fired only for STATUS_OPENED — the
 * recipient opened the message in a client that loaded the tracking pixel.
 */
class EmailOpened extends EmailEvent
{
}
