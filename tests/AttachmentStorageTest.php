<?php

namespace STS\Postmaster\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use STS\Postmaster\Attachments\AttachmentStatus;
use STS\Postmaster\Attachments\AttachmentStore;
use STS\Postmaster\Attachments\InlineImages;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Listeners\StashOutboundMetadata;
use STS\Postmaster\Models\EmailAttachment;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Postmaster as PostmasterManager;
use STS\Postmaster\Support\OutboundMetadata;
use STS\Postmaster\Tests\Stubs\AttachedMail;
use STS\Postmaster\Tests\Stubs\FullMail;
use STS\Postmaster\Tests\Stubs\InlineImageMail;
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
        $this->assertSame('logo.png', $attachment->filename);
        $this->assertSame(AttachmentStatus::Stored, $attachment->status);
    }

    public function testAFailingDiskRecordsTheAttachmentAsFailedWithoutThrowing()
    {
        config(['postmaster.persistence.attachments.disk' => 'does-not-exist']);

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', true);

        $this->assertSame(AttachmentStatus::Failed, EmailAttachment::first()->status);
    }

    /**
     * A disk only throws on a failed write when it is configured with
     * 'throw' => true, which is not Laravel's default. Otherwise put() reports
     * the failure by returning false, which is the shape a real S3 disk takes
     * when a write is throttled or the credentials have lapsed.
     */
    public function testAWriteThatReportsFailureIsNotRecordedAsStored()
    {
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::set('local', $disk);

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', true);

        $attachment = EmailAttachment::first();

        $this->assertSame(AttachmentStatus::Failed, $attachment->status);
        $this->assertNull($attachment->path);
        $this->assertNull($attachment->disk);
        $this->assertFalse($attachment->isAvailable());
    }

    /**
     * The consequence content-addressing adds: a row wrongly marked Stored is
     * not just one bad download, it is the entry every later message carrying
     * the same file dedupes onto. A failed write must not poison the checksum.
     */
    public function testAFailedWriteDoesNotPoisonLaterSendsOfTheSameFile()
    {
        $failing = \Mockery::mock(Filesystem::class);
        $failing->shouldReceive('put')->once()->andReturn(false);
        Storage::set('local', $failing);

        $store = app(AttachmentStore::class);
        $store->store($this->emailWith(), 'msg-1', true);

        // The outage passes; the same attachment goes out again.
        Storage::fake('local');
        $store->store($this->emailWith(), 'msg-2', true);

        $second = EmailAttachment::where('provider_message_id', 'msg-2')->first();

        $this->assertSame(AttachmentStatus::Stored, $second->status);
        Storage::disk('local')->assertExists($second->path);
        $this->assertSame('PDF DATA', Storage::disk('local')->get($second->path));
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

    public function testASharedFileSurvivesUntilItsLastReferenceGoes()
    {
        Storage::fake('local');

        $store = app(AttachmentStore::class);
        $store->store($this->emailWith(), 'msg-1', true);
        $store->store($this->emailWith(), 'msg-2', true);

        [$first, $second] = EmailAttachment::all()->all();
        $path = $first->path;

        $this->assertSame(0, $store->forget($first, AttachmentStatus::Pruned));
        Storage::disk('local')->assertExists($path);
        $this->assertSame(AttachmentStatus::Pruned, $first->fresh()->status);
        $this->assertNull($first->fresh()->path);

        $this->assertSame(8, $store->forget($second, AttachmentStatus::Pruned));
        Storage::disk('local')->assertMissing($path);
    }

    public function testPruningRemovesBytesPastTheRetentionWindowAndKeepsTheRow()
    {
        Storage::fake('local');
        config(['postmaster.persistence.attachments.prune_after_days' => 30]);

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', true);

        $attachment = EmailAttachment::first();
        $path = $attachment->path;
        $attachment->forceFill(['created_at' => now()->subDays(45)])->save();

        Artisan::call('postmaster:prune', ['--attachments' => true]);

        $attachment = $attachment->fresh();

        $this->assertSame(AttachmentStatus::Pruned, $attachment->status);
        $this->assertSame('invoice.pdf', $attachment->filename);
        $this->assertNull($attachment->path);
        Storage::disk('local')->assertMissing($path);
    }

    public function testPruningLeavesAttachmentsInsideTheWindowAlone()
    {
        Storage::fake('local');
        config(['postmaster.persistence.attachments.prune_after_days' => 30]);

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', true);

        Artisan::call('postmaster:prune', ['--attachments' => true]);

        $this->assertSame(AttachmentStatus::Stored, EmailAttachment::first()->status);
    }

    public function testPruningWithNoFlagsStillRunsEveryPass()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.attachments.prune_after_days' => 30,
            'postmaster.persistence.prune_content_after_days'     => 30,
        ]);

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', true);
        EmailAttachment::first()->forceFill(['created_at' => now()->subDays(45)])->save();

        Artisan::call('postmaster:prune');

        $this->assertSame(AttachmentStatus::Pruned, EmailAttachment::first()->status);
    }

    public function testADryRunReportsWithoutRemovingAnything()
    {
        Storage::fake('local');
        config(['postmaster.persistence.attachments.prune_after_days' => 30]);

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', true);
        EmailAttachment::first()->forceFill(['created_at' => now()->subDays(45)])->save();

        Artisan::call('postmaster:prune', ['--attachments' => true, '--dry-run' => true]);

        $attachment = EmailAttachment::first();

        $this->assertSame(AttachmentStatus::Stored, $attachment->status);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function testEvictionReclaimsOldestFilesUntilUsageFitsTheBudget()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.attachments.prune_after_days' => 0,
            'postmaster.persistence.attachments.max_disk_usage'   => 20,
        ]);

        $store = app(AttachmentStore::class);

        // Three distinct 10-byte files: 30 bytes against a 20-byte ceiling.
        foreach (['AAAAAAAAAA', 'BBBBBBBBBB', 'CCCCCCCCCC'] as $index => $body) {
            $store->store($this->emailWith($body, "file{$index}.pdf"), "msg-{$index}", true);
            EmailAttachment::where('filename', "file{$index}.pdf")
                ->update(['created_at' => now()->subDays(10 - $index)]);
        }

        $this->assertSame(30, $store->usage());

        Artisan::call('postmaster:prune', ['--attachments' => true]);

        // The oldest file goes; usage now fits.
        $this->assertSame(20, $store->usage());
        $this->assertSame(AttachmentStatus::Evicted, EmailAttachment::where('filename', 'file0.pdf')->first()->status);
        $this->assertSame(AttachmentStatus::Stored, EmailAttachment::where('filename', 'file2.pdf')->first()->status);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function testEvictionTakesEveryReferenceToASharedFileTogether()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.attachments.prune_after_days' => 0,
            'postmaster.persistence.attachments.max_disk_usage'   => 1,
        ]);

        $store = app(AttachmentStore::class);
        $store->store($this->emailWith('SHARED'), 'msg-1', true);
        $store->store($this->emailWith('SHARED'), 'msg-2', true);

        Artisan::call('postmaster:prune', ['--attachments' => true]);

        $this->assertCount(2, EmailAttachment::all());
        $this->assertCount(0, EmailAttachment::where('status', AttachmentStatus::Stored)->get());
        $this->assertSame(0, $store->usage());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function testEvictionIsSkippedWhenNoBudgetIsSet()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.attachments.prune_after_days' => 0,
            'postmaster.persistence.attachments.max_disk_usage'   => null,
        ]);

        app(AttachmentStore::class)->store($this->emailWith(), 'msg-1', true);

        Artisan::call('postmaster:prune', ['--attachments' => true]);

        $this->assertSame(AttachmentStatus::Stored, EmailAttachment::first()->status);
    }

    public function testResendReattachesStoredBytes()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new FullMail);

        $sent = [];
        Event::listen(MessageSent::class, function ($event) use (&$sent) {
            $sent[] = $event->message;
        });

        Postmaster::resend(EmailMessage::first());

        $parts = end($sent)->getAttachments();

        $this->assertCount(1, $parts);
        $this->assertSame('invoice.pdf', $parts[0]->getFilename());
        $this->assertSame('PDF DATA', $parts[0]->getBody());
    }

    public function testResendSkipsAttachmentsWhoseBytesAreGone()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new FullMail);

        app(AttachmentStore::class)->forget(EmailAttachment::first(), AttachmentStatus::Pruned);

        $sent = [];
        Event::listen(MessageSent::class, function ($event) use (&$sent) {
            $sent[] = $event->message;
        });

        Postmaster::resend(EmailMessage::first());

        $this->assertCount(0, end($sent)->getAttachments());
    }

    public function testAnInlinePartIsReEmbeddedUnderItsOriginalContentId()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new InlineImageMail);

        $sent = [];
        Event::listen(MessageSent::class, function ($event) use (&$sent) {
            $sent[] = $event->message;
        });

        Postmaster::resend(EmailMessage::first());

        $replay = end($sent);
        $part   = $replay->getAttachments()[0];

        $this->assertSame('inline', $part->getDisposition());
        $this->assertSame('logo.png', $part->getFilename());

        // The invariant that actually matters: once serialized, the body
        // carries no unresolved cid:logo.png. Symfony rewrites cid:filename
        // to the part's real cid on the way out — if the part hadn't come
        // back under its filename, that reference would survive verbatim and
        // the image would break in the recipient's client.
        $rendered = $replay->toString();

        $this->assertStringNotContainsString('cid:logo.png', $rendered);
        $this->assertStringContainsString("Content-ID: <{$part->getContentId()}>", $rendered);
    }

    public function testThePreviewResolvesAnEmbeddedImageIntoADataUri()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new InlineImageMail);

        $message = EmailMessage::first();

        $this->assertStringContainsString(
            'data:image/png;base64,'.base64_encode('PNG DATA'),
            $message->previewBody()
        );
        $this->assertStringNotContainsString('cid:logo.png', $message->previewBody());
        $this->assertFalse($message->hasUnresolvedInlineImages());
    }

    public function testThePreviewReportsAnEmbeddedImageItCannotSupply()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new InlineImageMail);

        app(AttachmentStore::class)->forget(EmailAttachment::first(), AttachmentStatus::Evicted);

        $message = EmailMessage::first();

        $this->assertStringContainsString('cid:logo.png', $message->previewBody());
        $this->assertTrue($message->hasUnresolvedInlineImages());
    }

    public function testAnEmbeddedImageOverTheInlineCapIsLeftUnresolved()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new InlineImageMail);

        // Same bytes, but recorded as larger than we're willing to inline.
        EmailAttachment::first()->forceFill(['size' => InlineImages::MAX_INLINE_SIZE + 1])->save();

        $this->assertTrue(EmailMessage::first()->hasUnresolvedInlineImages());
    }

    public function testTheLongestFilenameIsSubstitutedFirst()
    {
        Storage::fake('local');

        // "logo.png" is a prefix of "logo.png.bak": substituting the short one
        // first would corrupt the longer reference.
        $email = (new Email)->subject('Branded')
            ->html('<img src="cid:logo.png"><img src="cid:logo.png.bak">');
        $email->embed('SHORT', 'logo.png', 'image/png');
        $email->embed('LONGER', 'logo.png.bak', 'image/png');

        app(AttachmentStore::class)->store($email, 'msg-1', true);

        $message = EmailMessage::create([
            'provider_message_id' => 'msg-1',
            'to_address'          => 'to@example.com',
            'html_body'           => '<img src="cid:logo.png"><img src="cid:logo.png.bak">',
        ]);

        $preview = $message->previewBody();

        $this->assertStringContainsString('base64,'.base64_encode('SHORT'), $preview);
        $this->assertStringContainsString('base64,'.base64_encode('LONGER'), $preview);
        $this->assertFalse($message->hasUnresolvedInlineImages());
    }
}
