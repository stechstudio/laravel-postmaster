<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use STS\Postmaster\Attachments\AttachmentStatus;
use STS\Postmaster\Models\EmailAttachment;
use STS\Postmaster\Models\EmailMessage;

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
}
