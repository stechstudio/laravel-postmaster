<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Notifications\Messages\MailMessage;
use STS\Postmaster\Concerns\TracksMailMessage;

/**
 * A custom MailMessage subclass with the TracksMailMessage trait — proving the
 * trait works on a notification message, not just Mailables.
 */
class TrackedMailMessage extends MailMessage
{
    use TracksMailMessage;
}
