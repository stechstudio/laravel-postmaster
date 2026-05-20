<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Notifications\Messages\MailMessage;
use STS\Postmaster\Concerns\TracksEmailEvents;

/**
 * A MailMessage subclass with the TracksEmailEvents trait — proving the trait
 * works on anything exposing withSymfonyMessage(), not just Mailables.
 */
class TrackedMailMessage extends MailMessage
{
    use TracksEmailEvents;
}
