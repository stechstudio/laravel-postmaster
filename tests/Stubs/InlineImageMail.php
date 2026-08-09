<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Mail\Mailable;

class InlineImageMail extends Mailable
{
    public function build()
    {
        return $this->from('sender@example.com')
            ->subject('Branded')
            ->html('<img src="cid:logo.png">')
            ->withSymfonyMessage(fn ($message) => $message->embed('PNG DATA', 'logo.png', 'image/png'));
    }
}
