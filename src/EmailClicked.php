<?php

namespace STS\Postmaster;

/**
 * The targeted variant of EmailEvent fired only for STATUS_CLICKED — the
 * recipient clicked a tracked link. `$event->clickedUrl()` carries the URL.
 */
class EmailClicked extends EmailEvent
{
}
