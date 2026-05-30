<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Tracking;

/**
 * Coverage for the resend API — Postmaster::resend(), $message->resend(),
 * Tracking::resentFrom, the resent_from_id FK that links the chain, and
 * the resendChain() walker the dashboard uses.
 */
class ResendTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.persistence.store_content', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('mail.default', 'array');
    }

    public function testResendCreatesANewRowLinkedBackToTheOriginal()
    {
        Mail::raw('first send', function ($m) {
            $m->to('recipient@example.com')->subject('Greetings');
        });

        $original = EmailMessage::first();
        // Mail::raw stamps the body as text. Either text or html is enough
        // to resend; the precondition is that we have *some* stored content.
        $this->assertNotEmpty(
            $original->text_body ?: $original->html_body,
            'precondition: content captured'
        );

        Postmaster::resend($original);

        $this->assertSame(2, EmailMessage::count());

        $resend = EmailMessage::where('id', '!=', $original->id)->first();
        $this->assertSame($original->id, $resend->resent_from_id);
        $this->assertSame('recipient@example.com', $resend->to_address);
        $this->assertSame('Greetings', $resend->subject);
        $this->assertContains('resent', (array) $resend->tags);
    }

    public function testEmailMessageResendMethodIsAShortcutToTheFacade()
    {
        Mail::raw('first send', function ($m) {
            $m->to('recipient@example.com')->subject('Hi');
        });

        EmailMessage::first()->resend();

        $this->assertSame(2, EmailMessage::count());
        $resend = EmailMessage::latest('id')->first();
        $this->assertSame(EmailMessage::min('id'), $resend->resent_from_id);
    }

    public function testResendByIdLooksUpTheRecord()
    {
        Mail::raw('first send', function ($m) {
            $m->to('r@example.com')->subject('Hi');
        });

        Postmaster::resend(EmailMessage::first()->id);

        $this->assertSame(2, EmailMessage::count());
    }

    public function testResendThrowsWhenNoStoredContent()
    {
        // Send with content storage off so the original has no body.
        config(['postmaster.persistence.store_content' => false]);

        Mail::raw('no content stored', function ($m) {
            $m->to('r@example.com')->subject('Hi');
        });

        $original = EmailMessage::first();
        $this->assertEmpty($original->html_body);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no stored content');

        Postmaster::resend($original);
    }

    public function testResendChainReturnsTheFullLineageInOrder()
    {
        Mail::raw('first send', function ($m) {
            $m->to('r@example.com')->subject('Hi');
        });
        $original = EmailMessage::first();

        Postmaster::resend($original);
        Postmaster::resend(EmailMessage::latest('id')->first());

        $chain = EmailMessage::latest('id')->first()->resendChain();

        $this->assertCount(3, $chain);
        $this->assertSame($original->id, $chain[0]->id, 'first chain entry is the root original');
        $this->assertSame($original->id, $chain[1]->resent_from_id, 'second is a direct resend of the original');
        $this->assertSame($chain[1]->id, $chain[2]->resent_from_id, 'third is a resend of the second');
    }

    public function testResendChainCalledOnTheRootStillReturnsAllDescendants()
    {
        Mail::raw('first send', function ($m) {
            $m->to('r@example.com')->subject('Hi');
        });
        $original = EmailMessage::first();
        Postmaster::resend($original);

        $chain = $original->resendChain();

        $this->assertCount(2, $chain);
        $this->assertSame($original->id, $chain[0]->id);
    }

    public function testTrackingResentFromIsRespected()
    {
        Mail::raw('first send', function ($m) {
            $m->to('r@example.com')->subject('Hi');
        });
        $original = EmailMessage::first();

        // App-side: a Mailable declares resent_from directly via Tracking
        // rather than going through Postmaster::resend(). Verify the FK
        // still lands on the new row.
        Mail::send(new Stubs\TrackingResentFromMail($original));

        $latest = EmailMessage::latest('id')->first();
        $this->assertSame($original->id, $latest->resent_from_id);
    }
}
