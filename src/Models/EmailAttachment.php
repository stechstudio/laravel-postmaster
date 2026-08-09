<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use STS\Postmaster\Attachments\AttachmentStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One attachment carried by one outbound email, recorded when either content
 * storage or attachment storage is on. The bytes live on a configurable disk
 * at a content-addressed path; this row is the metadata and the reference.
 *
 * Rows are keyed to the *submission* (provider_message_id), not to a single
 * email_messages row, because one submission writes one row per envelope
 * recipient and they all carried the same files.
 *
 * @property string $provider_message_id
 * @property string $filename
 * @property string|null $mime_type
 * @property int $size
 * @property string $checksum
 * @property string $disposition  'attachment' | 'inline'
 * @property string|null $disk
 * @property string|null $path
 * @property AttachmentStatus $status
 * @property \Illuminate\Support\Carbon|null $stored_at
 */
class EmailAttachment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status'    => AttachmentStatus::class,
        'size'      => 'integer',
        'stored_at' => 'datetime',
    ];

    /**
     * A fresh instance of the configured (swappable) model. Use this anywhere
     * a query starts from, so an app that swapped in a subclass via
     * persistence.attachment_model gets that subclass everywhere.
     */
    public static function model(): self
    {
        $class = config('postmaster.persistence.attachment_model', static::class);

        return new $class;
    }

    public function getTable(): string
    {
        return config('postmaster.persistence.attachments_table', 'email_attachments');
    }

    public function getConnectionName()
    {
        return config('postmaster.persistence.connection') ?: parent::getConnectionName();
    }

    /**
     * Whether the bytes are still retrievable. False once pruned or evicted,
     * and for attachments that were never stored (oversize, policy, failure).
     */
    public function isAvailable(): bool
    {
        return $this->status === AttachmentStatus::Stored
            && $this->path !== null
            && $this->disk !== null;
    }

    /**
     * The stored bytes. Only call this when isAvailable() is true — a missing
     * disk or path is a bug in the caller, not a condition to swallow.
     */
    public function contents(): string
    {
        return Storage::disk($this->disk)->get($this->path);
    }

    /**
     * Stream the stored bytes back as a download. The controller calls this
     * rather than reaching for Storage itself — and rather than handing out a
     * cloud temporary URL, which would be a second authorization path and a
     * link that outlives the session that requested it.
     */
    public function download(): StreamedResponse
    {
        return Storage::disk($this->disk)->download($this->path, $this->filename);
    }
}
