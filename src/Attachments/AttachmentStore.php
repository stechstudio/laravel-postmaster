<?php

namespace STS\Postmaster\Attachments;

use Illuminate\Support\Facades\Storage;
use STS\Postmaster\Models\EmailAttachment;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Captures the attachments carried by an outbound email, and reclaims them
 * again when they age out.
 *
 * Bytes are content-addressed by sha256, so identical content is written once
 * however many messages carry it — the logo embedded on every send costs one
 * file, not one per message. That makes deletion a reference-counting problem:
 * a file is only unlinked once no Stored row still points at its checksum.
 *
 * Called from the recorder and the prune command. Never from UI code — the
 * dashboard reaches attachments through EmailAttachment's own affordances.
 */
class AttachmentStore
{
    /**
     * Record every attachment on the message. Metadata is always written;
     * $storeBytes decides whether the contents go to disk with it.
     *
     * Called once per submission, not once per envelope recipient, so a
     * To + 2 Cc send produces one set of rows that all three messages share.
     */
    public function store(Email $message, string $providerMessageId, bool $storeBytes): void
    {
        foreach ($message->getAttachments() as $part) {
            $body     = $part->getBody();
            $checksum = hash('sha256', $body);
            $size     = strlen($body);

            EmailAttachment::model()->newQuery()->create([
                'provider_message_id' => $providerMessageId,
                'filename'            => $part->getFilename() ?: 'attachment',
                'mime_type'           => $part->getContentType(),
                'size'                => $size,
                'checksum'            => $checksum,
                'disposition'         => $part->getDisposition() === 'inline' ? 'inline' : 'attachment',
            ] + $this->placement($checksum, $body, $size, $storeBytes));
        }
    }

    /**
     * Total bytes held on disk, counted over *distinct checksums*. Summing
     * rows would report every reference to a shared file as fresh usage —
     * 400,000 references to one logo would look like 400,000 copies, and
     * eviction would spin without ever reclaiming that phantom space.
     */
    public function usage(): int
    {
        return (int) EmailAttachment::model()->newQuery()
            ->where('status', AttachmentStatus::Stored)
            ->select('checksum', 'size')
            ->distinct()
            ->get()
            ->sum('size');
    }

    /**
     * The on-disk cost of one checksum group — its size counted once,
     * regardless of how many rows reference it.
     */
    public function sizeOf(string $checksum): int
    {
        return (int) EmailAttachment::model()->newQuery()
            ->where('checksum', $checksum)
            ->where('status', AttachmentStatus::Stored)
            ->value('size');
    }

    /**
     * Where this attachment's bytes end up: the status, disk, and path columns
     * for the row. Reuses an existing file when the checksum is already on
     * disk, so nothing is written twice.
     *
     * @return array<string, mixed>
     */
    protected function placement(string $checksum, string $body, int $size, bool $storeBytes): array
    {
        if (! $storeBytes) {
            return ['status' => AttachmentStatus::NotStored];
        }

        $max = (int) config('postmaster.persistence.attachments.max_size');

        if ($max > 0 && $size > $max) {
            return ['status' => AttachmentStatus::Oversize];
        }

        if ($existing = $this->existing($checksum)) {
            return [
                'status'    => AttachmentStatus::Stored,
                'disk'      => $existing->disk,
                'path'      => $existing->path,
                'stored_at' => now(),
            ];
        }

        $disk = (string) config('postmaster.persistence.attachments.disk', 'local');
        $path = $this->pathFor($checksum);

        // The disk is a genuine external boundary — S3 can be down, a local
        // volume can be full. MessageSent fires after the send, so a failure
        // here can't unsend anything and must not blow up the request. The
        // row still lands, marked Failed, and the exception is reported.
        //
        // A failed write surfaces two different ways: put() throws only when
        // the disk sets 'throw' => true, and otherwise reports the failure by
        // returning false. Both have to end up Failed. Recording Stored off a
        // write that didn't happen would leave the row pointing at bytes that
        // aren't there — and because paths are content-addressed, every later
        // message carrying that same file would dedupe onto the same dead
        // path, turning one transient outage into a permanent hole.
        return rescue(function () use ($disk, $path, $body) {
            if (! Storage::disk($disk)->put($path, $body)) {
                return ['status' => AttachmentStatus::Failed];
            }

            return [
                'status'    => AttachmentStatus::Stored,
                'disk'      => $disk,
                'path'      => $path,
                'stored_at' => now(),
            ];
        }, ['status' => AttachmentStatus::Failed]);
    }

    /**
     * Release one attachment's claim on its bytes, marking it with the reason.
     * The file is unlinked only when no other Stored row shares its checksum —
     * the reference count that content-addressing makes necessary.
     *
     * Returns the bytes actually reclaimed: 0 when the file lives on for
     * another reference.
     */
    public function forget(EmailAttachment $attachment, AttachmentStatus $reason): int
    {
        $freed = 0;

        if ($attachment->isAvailable() && ! $this->referencedElsewhere($attachment)) {
            $disk = $attachment->disk;
            $path = $attachment->path;

            rescue(fn () => Storage::disk($disk)->delete($path));

            $freed = $attachment->size;
        }

        $attachment->forceFill([
            'status' => $reason,
            'disk'   => null,
            'path'   => null,
        ])->save();

        return $freed;
    }

    /**
     * Release a whole checksum group at once — every row that points at one
     * file. Used by eviction, which reclaims space and therefore has to take
     * all of a file's references together: dropping one at a time frees
     * nothing until the last.
     */
    public function forgetChecksum(string $checksum, AttachmentStatus $reason): int
    {
        $rows = EmailAttachment::model()->newQuery()
            ->where('checksum', $checksum)
            ->where('status', AttachmentStatus::Stored)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $first = $rows->first();
        $freed = $first->size;

        if ($first->disk !== null && $first->path !== null) {
            $disk = $first->disk;
            $path = $first->path;

            rescue(fn () => Storage::disk($disk)->delete($path));
        }

        foreach ($rows as $row) {
            $row->forceFill(['status' => $reason, 'disk' => null, 'path' => null])->save();
        }

        return $freed;
    }

    /**
     * Whether another Stored row still points at this attachment's bytes.
     */
    protected function referencedElsewhere(EmailAttachment $attachment): bool
    {
        return EmailAttachment::model()->newQuery()
            ->where('checksum', $attachment->checksum)
            ->where('status', AttachmentStatus::Stored)
            ->whereKeyNot($attachment->getKey())
            ->exists();
    }

    /**
     * A row whose bytes for this checksum are already on disk, if any.
     */
    protected function existing(string $checksum): ?EmailAttachment
    {
        return EmailAttachment::model()->newQuery()
            ->where('checksum', $checksum)
            ->where('status', AttachmentStatus::Stored)
            ->whereNotNull('path')
            ->first();
    }

    /**
     * Content-addressed path with two levels of fan-out, so no single
     * directory ends up holding millions of entries.
     */
    protected function pathFor(string $checksum): string
    {
        $prefix = trim((string) config('postmaster.persistence.attachments.path', 'postmaster/attachments'), '/');

        return $prefix.'/'.substr($checksum, 0, 2).'/'.substr($checksum, 2, 2).'/'.$checksum;
    }
}
