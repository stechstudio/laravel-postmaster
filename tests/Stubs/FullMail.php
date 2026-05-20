<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Mail\Mailable;

class FullMail extends Mailable
{
    public function build()
    {
        return $this->from('sender@example.com')
            ->subject('Full email')
            ->html('<p>Body</p>')
            ->withSymfonyMessage(fn ($message) => $message->text('Plain body'))
            ->attachData('PDF DATA', 'invoice.pdf', ['mime' => 'application/pdf']);
    }
}
