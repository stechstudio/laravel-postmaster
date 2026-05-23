<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Notifications\Messages\MailMessage;
use STS\Postmaster\Concerns\WithTracking;

/**
 * A user-defined MailMessage subclass with the WithTracking trait — proving
 * the trait works on a notification message you maintain yourself, not just
 * on the package's drop-in TrackedMailMessage.
 */
class CustomTrackedMailMessage extends MailMessage
{
    use WithTracking;
}
