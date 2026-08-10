<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use STS\Postmaster\Attachments\AttachmentStatus;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
     * This attachment's size, for humans.
     */
    public function humanSize(): string
    {
        return static::humanBytes($this->size);
    }

    /**
     * A byte count for humans. Shared by the dashboard's attachment rows and
     * the prune command's report lines, so the two never drift.
     */
    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit  = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $value : number_format($value, $value < 10 ? 1 : 0))
            .' '.$units[$unit];
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
     * A response delivering the stored bytes. Reached only through the
     * dashboard's own endpoint, never linked directly, so the dashboard gate
     * and the check that this attachment belongs to the message it was
     * requested under both run before anything is handed out.
     *
     * From there, a disk that can mint a temporary URL (S3 and friends) gets a
     * redirect to one, so the bytes travel from the bucket instead of through
     * a PHP worker. That URL is bearer authority for its lifetime — a leaked
     * link works without the gate until it expires — which is the whole reason
     * the TTL is short and configurable. Disks that can't mint one, including
     * a plain local disk, stream through the app as before.
     */
    public function download(): StreamedResponse|RedirectResponse
    {
        $disk = Storage::disk($this->disk);
        $ttl  = (int) config('postmaster.persistence.attachments.signed_url_ttl');

        // A redirect is only worth making when the bytes then come from
        // somewhere other than this application. A local disk serves them from
        // this same process either way, so redirecting to it buys no egress
        // and costs the filename: the response-header overrides below are an
        // S3 convention that Laravel's local serve route ignores, which would
        // deliver the file inline and named after its sha256.
        //
        // providesTemporaryUrls() is on Laravel's adapter rather than the
        // Filesystem contract, so a disk that isn't one has to be tolerated.
        $remote = config("filesystems.disks.{$this->disk}.driver") !== 'local';

        if ($ttl > 0 && $remote && method_exists($disk, 'providesTemporaryUrls') && $disk->providesTemporaryUrls()) {
            return redirect()->away($disk->temporaryUrl($this->path, now()->addSeconds($ttl), [
                // Paths are content-addressed, so the object has neither the
                // original name nor an extension to infer a type from. Without
                // these the file arrives called 3f8a9c… and typed as binary.
                'ResponseContentDisposition' => $this->contentDisposition(),
                'ResponseContentType'        => $this->mime_type ?: 'application/octet-stream',
            ]));
        }

        return $disk->download($this->path, $this->filename);
    }

    /**
     * A Content-Disposition value carrying the original filename, with the
     * ASCII fallback that names outside it require.
     */
    protected function contentDisposition(): string
    {
        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $this->filename,
            str_replace('%', '', Str::ascii($this->filename)),
        );
    }
}
