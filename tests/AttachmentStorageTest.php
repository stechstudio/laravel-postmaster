<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use STS\Postmaster\Attachments\AttachmentStatus;
use STS\Postmaster\Attachments\AttachmentStore;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Listeners\StashOutboundMetadata;
use STS\Postmaster\Models\EmailAttachment;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Postmaster as PostmasterManager;
use STS\Postmaster\Support\OutboundMetadata;
use STS\Postmaster\Tests\Stubs\AttachedMail;
use STS\Postmaster\Tests\Stubs\FullMail;
use STS\Postmaster\Tracking;
use Symfony\Component\Mime\Email;

class AttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('postmaster.persistence.enabled', true);
    }

    public function testAttachmentsResolveThroughTheProviderMessageIdRelation()
    {
        EmailMessage::create(['provider_message_id' => 'abc', 'to_address' => 'to@example.com']);
        EmailMessage::create(['provider_message_id' => 'abc', 'to_address' => 'cc@example.com']);

        EmailAttachment::create([
            'provider_message_id' => 'abc',
            'filename'            => 'invoice.pdf',
            'mime_type'           => 'application/pdf',
            'size'                => 8,
            'checksum'            => str_repeat('a', 64),
            'disposition'         => 'attachment',
            'status'              => AttachmentStatus::Stored,
            'disk'                => 'local',
            'path'                => 'postmaster/attachments/aa/aa/'.str_repeat('a', 64),
        ]);

        foreach (EmailMessage::all() as $message) {
            $this->assertCount(1, $message->attachments);
            $this->assertSame('invoice.pdf', $message->attachments->first()->filename);
            $this->assertTrue($message->attachments->first()->isAvailable());
        }
    }

    public function testAnAttachmentWithoutStoredBytesIsNotAvailable()
    {
        $attachment = EmailAttachment::create([
            'provider_message_id' => 'abc',
            'filename'            => 'huge.zip',
            'size'                => 99_000_000,
            'checksum'            => str_repeat('b', 64),
            'disposition'         => 'attachment',
            'status'              => AttachmentStatus::Oversize,
        ]);

        $this->assertFalse($attachment->isAvailable());
        $this->assertSame(AttachmentStatus::Oversize, $attachment->status);
    }

    public function testLegacyAttachmentNamesAreStillReadable()
    {
        $message = EmailMessage::create([
            'provider_message_id'     => 'legacy',
            'to_address'              => 'to@example.com',
            'legacy_attachment_names' => ['old.pdf'],
        ]);

        $this->assertSame(['old.pdf'], $message->fresh()->legacyAttachmentNames());
        $this->assertCount(0, $message->attachments);
    }

    public function testAttachmentStorageIsOffByDefault()
    {
        $this->assertFalse(config('postmaster.persistence.attachments.store'));
        $this->assertSame(10 * 1024 * 1024, config('postmaster.persistence.attachments.max_size'));
        $this->assertNull(config('postmaster.persistence.attachments.max_disk_usage'));
        $this->assertSame(30, config('postmaster.persistence.attachments.prune_after_days'));
    }

    public function testTheAttachmentResolverReturnsNullUntilOneIsRegistered()
    {
        $message = (new Email)->subject('Hello');

        $this->assertNull(app(PostmasterManager::class)->resolveStoreAttachments($message));
    }

    public function testTheAttachmentResolverDecidesPerMessage()
    {
        Postmaster::storeAttachmentsWhen(
            fn (Email $message) => ! str_contains((string) $message->getSubject(), 'reset')
        );

        $keep = (new Email)->subject('Your invoice');
        $skip = (new Email)->subject('Password reset');

        $postmaster = app(PostmasterManager::class);

        $this->assertTrue($postmaster->resolveStoreAttachments($keep));
        $this->assertFalse($postmaster->resolveStoreAttachments($skip));
    }

    public function testThePerMessageOverrideTravelsAsAStashedHeader()
    {
        $message = new Email;

        (app(PostmasterManager::class)->storeAttachments(false))($message);

        $this->assertTrue($message->getHeaders()->has(OutboundMetadata::HEADER_STORE_ATTACHMENTS));

        (new StashOutboundMetadata)->handle(new MessageSending($message));

        // Stripped from the wire, and readable from the in-process stash.
        $this->assertFalse($message->getHeaders()->has(OutboundMetadata::HEADER_STORE_ATTACHMENTS));
        $this->assertSame('0', OutboundMetadata::pull(spl_object_id($message))['store_attachments']);
    }

    public function testTrackingDeclaresAttachmentStorageOnAMailable()
    {
        $tracking = new Tracking(storeAttachments: false);

        $this->assertFalse($tracking->storeAttachments);
        $this->assertNull($tracking->storeContent);
    }

    protected function emailWith(string $body = 'PDF DATA', string $name = 'invoice.pdf'): Email
    {
        return (new Email)->subject('Invoice')->text('Hi')
            ->attach($body, $name, 'application/pdf');
    }

    public function testStoringWritesBytesAndRecordsMetadata()
    {
        Storage::fake('local');

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', true);

        $attachment = EmailAttachment::first();

        $this->assertSame('invoice.pdf', $attachment->filename);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame(8, $attachment->size);
        $this->assertSame(hash('sha256', 'PDF DATA'), $attachment->checksum);
        $this->assertSame('attachment', $attachment->disposition);
        $this->assertSame(AttachmentStatus::Stored, $attachment->status);
        $this->assertNotNull($attachment->stored_at);

        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame('PDF DATA', Storage::disk('local')->get($attachment->path));
    }

    public function testIdenticalContentIsWrittenOnceAndReferencedTwice()
    {
        Storage::fake('local');

        $store = app(AttachmentStore::class);
        $store->store($this->emailWith(), 'msg-1', true);
        $store->store($this->emailWith(), 'msg-2', true);

        $this->assertCount(2, EmailAttachment::all());
        $this->assertCount(1, EmailAttachment::all()->pluck('path')->unique());
        $this->assertCount(1, Storage::disk('local')->allFiles());

        // Usage counts distinct checksums, not rows.
        $this->assertSame(8, $store->usage());
    }

    public function testAnOversizeAttachmentRecordsMetadataWithoutBytes()
    {
        Storage::fake('local');
        config(['postmaster.persistence.attachments.max_size' => 4]);

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', true);

        $attachment = EmailAttachment::first();

        $this->assertSame(AttachmentStatus::Oversize, $attachment->status);
        $this->assertSame(8, $attachment->size);
        $this->assertNull($attachment->path);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function testMetadataIsRecordedWithoutBytesWhenStorageIsOff()
    {
        Storage::fake('local');

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', false);

        $attachment = EmailAttachment::first();

        $this->assertSame(AttachmentStatus::NotStored, $attachment->status);
        $this->assertSame('invoice.pdf', $attachment->filename);
        $this->assertNull($attachment->path);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function testInlinePartsKeepTheirContentIdAndDisposition()
    {
        Storage::fake('local');

        $email = (new Email)->subject('Branded')->html('<img src="cid:logo.png">');
        $email->embed('PNG DATA', 'logo.png', 'image/png');

        app(AttachmentStore::class)->store($email, 'msg-1', true);

        $attachment = EmailAttachment::first();

        $this->assertSame('inline', $attachment->disposition);
        $this->assertSame('logo.png', $attachment->content_id);
        $this->assertSame(AttachmentStatus::Stored, $attachment->status);
    }

    public function testAFailingDiskRecordsTheAttachmentAsFailedWithoutThrowing()
    {
        config(['postmaster.persistence.attachments.disk' => 'does-not-exist']);

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', true);

        $this->assertSame(AttachmentStatus::Failed, EmailAttachment::first()->status);
    }

    public function testEnvelopeSiblingsShareOneAttachmentSet()
    {
        Storage::fake('local');
        config(['postmaster.persistence.attachments.store' => true]);

        Mail::to('to@example.com')->cc('cc@example.com')->bcc('bcc@example.com')->send(new FullMail);

        $this->assertCount(3, EmailMessage::all());
        $this->assertCount(1, EmailAttachment::all());

        foreach (EmailMessage::all() as $message) {
            $this->assertCount(1, $message->attachments);
            $this->assertSame('invoice.pdf', $message->attachments->first()->filename);
        }
    }

    public function testAPerMessageOverrideBeatsTheConfigFlag()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.attachments.store' => true,
            // Content storage on so a metadata row is still written — with
            // both switches off there'd be no row at all to assert against.
            'postmaster.persistence.store_content'     => true,
        ]);

        Mail::to('to@example.com')->send((new AttachedMail)->dontStoreAttachments());

        $this->assertSame(AttachmentStatus::NotStored, EmailAttachment::first()->status);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function testADeclaredTrackingOverrideBeatsTheConfigFlag()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.attachments.store' => true,
            'postmaster.persistence.store_content'     => true,
        ]);

        Mail::to('to@example.com')->send(new AttachedMail(storeAttachments: false));

        $this->assertSame(AttachmentStatus::NotStored, EmailAttachment::first()->status);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function testAnOverrideOfBothSwitchesWritesNoAttachmentRowAtAll()
    {
        Storage::fake('local');
        config(['postmaster.persistence.attachments.store' => true]);

        Mail::to('to@example.com')->send((new AttachedMail)->dontStoreAttachments());

        $this->assertCount(0, EmailAttachment::all());
    }

    public function testADeclaredTrackingOverrideCanTurnStorageOn()
    {
        Storage::fake('local');

        Mail::to('to@example.com')->send(new AttachedMail(storeAttachments: true));

        $this->assertSame(AttachmentStatus::Stored, EmailAttachment::first()->status);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function testNoAttachmentRowsAreWrittenWhenBothSwitchesAreOff()
    {
        Storage::fake('local');

        Mail::to('to@example.com')->send(new FullMail);

        $this->assertCount(0, EmailAttachment::all());
    }

    public function testContentStorageAloneRecordsAttachmentMetadata()
    {
        Storage::fake('local');
        config(['postmaster.persistence.store_content' => true]);

        Mail::to('to@example.com')->send(new FullMail);

        $attachment = EmailAttachment::first();

        $this->assertSame('invoice.pdf', $attachment->filename);
        $this->assertSame(AttachmentStatus::NotStored, $attachment->status);
    }
}
