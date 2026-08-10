<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Mail\Mailable;

/**
 * The common shape of a real branded email: one genuine attachment the
 * recipient sees paperclipped on, plus a logo embedded in the body.
 */
class MixedAttachmentMail extends Mailable
{
    public function build()
    {
        return $this->from('sender@example.com')
            ->subject('Your invoice')
            ->html('<p>Invoice attached.</p><img src="cid:logo.png">')
            ->attachData('PDF DATA', 'invoice.pdf', ['mime' => 'application/pdf'])
            ->withSymfonyMessage(fn ($message) => $message->embed('PNG DATA', 'logo.png', 'image/png'));
    }
}
