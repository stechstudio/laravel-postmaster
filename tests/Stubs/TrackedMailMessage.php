<?php

namespace STS\EmailEvents\Tests\Stubs;

use Illuminate\Notifications\Messages\MailMessage;
use STS\EmailEvents\Concerns\TracksEmailEvents;

/**
 * A MailMessage subclass with the TracksEmailEvents trait — proving the trait
 * works on anything exposing withSymfonyMessage(), not just Mailables.
 */
class TrackedMailMessage extends MailMessage
{
    use TracksEmailEvents;
}
