# Attachment Storage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store the bytes of outbound email attachments — not just their filenames — so resend and release replay faithfully and support can download what actually went out.

**Architecture:** A new `email_attachments` table holds one metadata row per message-attachment, grouped by `provider_message_id` so all of a send's per-recipient `email_messages` rows share one attachment set. Bytes live on a configurable Laravel disk at content-addressed paths keyed by sha256, so identical content (an embedded logo on every send) is written once and refcounted. An independent `store_attachments` switch gets the same three-tier control surface content storage already has.

**Tech Stack:** PHP 8.3+, Laravel 12/13 (`illuminate/support`, `illuminate/filesystem`), Symfony Mime, PHPUnit 11+, Orchestra Testbench, PHPStan (larastan).

**Spec:** `docs/superpowers/specs/2026-08-09-attachment-storage-design.md`

## Global Constraints

- PHP `^8.3`; Laravel `^12.0|^13.0`. No new third-party dependencies beyond `illuminate/filesystem`.
- Attachment storage is **off by default** (`POSTMASTER_STORE_ATTACHMENTS=false`), matching `store_content`.
- Every model query starts from `Model::model()->newQuery()` so swappable model config is honored. Never `new EmailAttachment`.
- Config reads go through `config('postmaster.persistence.…')` — never a hardcoded table or disk name.
- `try`/`catch` only at external boundaries. Disk writes get `rescue()`; database writes stay unwrapped and fail loudly.
- UI code (controllers, Blade) never calls `AttachmentStore` or `Storage` directly — it goes through model affordances.
- Run the suite with `vendor/bin/phpunit`. Run a single test with `vendor/bin/phpunit --filter testName`.
- Lint with `vendor/bin/phpstan analyse` before each commit.
- No co-author or "Generated with" trailers in commit messages.

---

### Task 1: Schema, enum, models, and config

**Files:**
- Create: `database/migrations/2026_08_09_000000_create_email_attachments_table.php`
- Create: `database/migrations/2026_08_09_000001_rename_attachments_on_email_messages_table.php`
- Create: `src/Attachments/AttachmentStatus.php`
- Create: `src/Models/EmailAttachment.php`
- Modify: `src/Models/EmailMessage.php` (casts, relation, docblock)
- Modify: `config/postmaster.php` (persistence block)
- Modify: `composer.json` (require `illuminate/filesystem`)
- Test: `tests/AttachmentStorageTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces:
  - `STS\Postmaster\Attachments\AttachmentStatus` — backed string enum, cases `Stored`, `NotStored`, `Oversize`, `Pruned`, `Evicted`, `Failed`.
  - `STS\Postmaster\Models\EmailAttachment` with `static model(): self`, `getTable(): string`, `isAvailable(): bool`.
  - `EmailMessage::attachments(): HasMany` and `EmailMessage::legacyAttachmentNames(): array`.
  - Config keys `postmaster.persistence.attachments_table` and `postmaster.persistence.attachments.{store,disk,path,max_size,max_disk_usage,prune_after_days}`.

- [ ] **Step 1: Write the failing test**

Add to a new `tests/AttachmentStorageTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter AttachmentStorageTest`
Expected: FAIL — `Class "STS\Postmaster\Attachments\AttachmentStatus" not found`.

- [ ] **Step 3: Create the status enum**

`src/Attachments/AttachmentStatus.php`:

```php
<?php

namespace STS\Postmaster\Attachments;

/**
 * The lifecycle of a recorded attachment's *bytes*. The metadata row always
 * survives — only Stored means there is a file on disk behind it, so the
 * dashboard can say "invoice.pdf, 2.1 MB, evicted Aug 1" instead of showing
 * a dead link.
 */
enum AttachmentStatus: string
{
    /** Bytes are on disk at `path`. */
    case Stored = 'stored';

    /** Metadata only, by policy — attachment storage was off for this message. */
    case NotStored = 'not_stored';

    /** Exceeded attachments.max_size. Metadata recorded, bytes skipped. */
    case Oversize = 'oversize';

    /** Bytes removed by the retention window. */
    case Pruned = 'pruned';

    /** Bytes removed to stay under attachments.max_disk_usage. */
    case Evicted = 'evicted';

    /** The disk write raised. Metadata recorded, exception reported. */
    case Failed = 'failed';
}
```

- [ ] **Step 4: Create the migrations**

`database/migrations/2026_08_09_000000_create_email_attachments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.attachments_table', 'email_attachments');
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            // Grouping key. One outbound submission writes one email_messages
            // row per envelope recipient, all sharing this id — so a single
            // attachment set serves every one of them.
            $table->string('provider_message_id')->index();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            // Byte length, recorded even when the bytes themselves aren't.
            $table->unsignedBigInteger('size')->default(0);
            // sha256 of the contents: the dedup key on write and the
            // reference-count key on delete.
            $table->string('checksum', 64)->index();
            // Symfony's own vocabulary: 'attachment' or 'inline'.
            $table->string('disposition', 16)->default('attachment');
            // CID for inline parts, so re-embedding on resend is faithful.
            $table->string('content_id')->nullable();
            // Recorded per row so changing the configured disk later doesn't
            // orphan files written under the old one.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('status')->index();
            $table->timestamp('stored_at')->nullable();
            $table->timestamps();
            // Drives both the retention window and eviction ordering.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
```

`database/migrations/2026_08_09_000001_rename_attachments_on_email_messages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frees the `attachments` name for the email_attachments relation. Eloquent
 * resolves attributes before relations, so a column of that name would make
 * $message->attachments permanently unreachable as a HasMany.
 *
 * The old column keeps its data for pre-upgrade rows, read through
 * EmailMessage::legacyAttachmentNames().
 */
return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.messages_table', 'email_messages');
    }

    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->renameColumn('attachments', 'legacy_attachment_names');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->renameColumn('legacy_attachment_names', 'attachments');
        });
    }
};
```

- [ ] **Step 5: Create the EmailAttachment model**

`src/Models/EmailAttachment.php`:

```php
<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\Attachments\AttachmentStatus;

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
 * @property string|null $content_id
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
}
```

- [ ] **Step 6: Wire the relation onto EmailMessage**

In `src/Models/EmailMessage.php`, change the `attachments` cast to the renamed column and add the relation. Replace the `'attachments' => 'array',` line in `$casts` with:

```php
        'legacy_attachment_names' => 'array',
```

Update the class docblock property line `@property array|null $attachments` to:

```php
 * @property array|null $legacy_attachment_names
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EmailAttachment> $attachments
```

Add these methods (place them beside the existing `activity()` relation):

```php
    /**
     * The attachments this email carried. Keyed on provider_message_id rather
     * than this row's id, because one submission writes a row per envelope
     * recipient and they all carried the same files — so To, Cc, and Bcc rows
     * resolve one shared set instead of three copies.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class, 'provider_message_id', 'provider_message_id');
    }

    /**
     * Attachment filenames recorded before the email_attachments table
     * existed. Pre-upgrade rows kept only names in a JSON column; the
     * dashboard falls back to this when the relation is empty.
     *
     * @return array<int, string>
     */
    public function legacyAttachmentNames(): array
    {
        return $this->legacy_attachment_names ?? [];
    }
```

- [ ] **Step 7: Add the config block**

In `config/postmaster.php`, inside the `persistence` array, replace the `store_content` comment block's neighbours by adding after `prune_content_after_days`:

```php
        /*
         * The table recording one row per attachment carried by an outbound
         * email. Metadata is written whenever either content storage or
         * attachment storage is on; the bytes only when the latter is.
         */
        'attachments_table' => 'email_attachments',
        'attachment_model'  => null,

        /*
         * Attachment storage. Off by default, and independent of
         * store_content — so you can keep an invoice PDF while discarding a
         * body that carries a magic-login link.
         *
         * Bytes are written to `disk` at content-addressed paths, so identical
         * content (a logo embedded on every send) is stored once no matter how
         * many messages reference it.
         *
         *   store            Capture attachment bytes at all.
         *   disk             Any configured filesystem disk. Use an s3 disk
         *                    and size stops being a concern.
         *   path             Prefix under that disk.
         *   max_size         Per-file ceiling in bytes. Larger attachments
         *                    record their metadata and skip the bytes.
         *   max_disk_usage   Total ceiling in bytes. When set, the daily prune
         *                    evicts least-recently-referenced files until
         *                    usage fits. null leaves it unbounded.
         *   prune_after_days Retention for the bytes. The metadata row
         *                    survives so the record of what was sent stays
         *                    intact. 0 or null disables pruning.
         */
        'attachments' => [
            'store'            => env('POSTMASTER_STORE_ATTACHMENTS', false),
            'disk'             => env('POSTMASTER_ATTACHMENTS_DISK', 'local'),
            'path'             => 'postmaster/attachments',
            'max_size'         => env('POSTMASTER_ATTACHMENTS_MAX_SIZE', 10 * 1024 * 1024),
            'max_disk_usage'   => env('POSTMASTER_ATTACHMENTS_MAX_DISK_USAGE'),
            'prune_after_days' => env('POSTMASTER_PRUNE_ATTACHMENTS_AFTER_DAYS', 30),
        ],
```

- [ ] **Step 8: Declare the filesystem dependency**

In `composer.json`, add to `require` (after `illuminate/support`):

```json
        "illuminate/filesystem": "^12.0|^13.0",
```

Run: `composer update illuminate/filesystem --no-interaction`

- [ ] **Step 9: Fix the now-stale reference in Prune**

In `src/Console/Prune.php::pruneContent()`, the content purge still names the renamed column. Change `->orWhereNotNull('attachments')` to `->orWhereNotNull('legacy_attachment_names')` and `'attachments' => null,` to `'legacy_attachment_names' => null,`.

- [ ] **Step 10: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter AttachmentStorageTest`
Expected: PASS (4 tests).

Then run the full suite to catch the renamed column elsewhere:

Run: `vendor/bin/phpunit`
Expected: two failures in `PersistenceTest` — `testFullMessageContentIsStoredWhenEnabled` and `testMessageContentIsNotStoredByDefault` still assert on `$record->attachments`. Leave them failing; Task 4 replaces those assertions when capture is wired up.

- [ ] **Step 11: Lint**

Run: `vendor/bin/phpstan analyse`
Expected: no errors.

- [ ] **Step 12: Commit**

```bash
git add database/migrations src/Attachments src/Models config/postmaster.php composer.json composer.lock src/Console/Prune.php tests/AttachmentStorageTest.php
git commit -m "feat: add email_attachments table and model

Records one row per attachment carried by an outbound email, keyed to
the submission rather than a single recipient row so To/Cc/Bcc share
one set. Status tracks whether the bytes are still retrievable.

Renames the email_messages attachments column to
legacy_attachment_names: Eloquent resolves attributes before
relations, so the old name would shadow the new HasMany."
```

---

### Task 2: Control surface — three tiers

**Files:**
- Modify: `src/Support/OutboundMetadata.php` (new header constant)
- Modify: `src/Listeners/StashOutboundMetadata.php` (header map)
- Modify: `src/Postmaster.php` (builder, resolver, resolution)
- Modify: `src/Concerns/WithTracking.php` (fluent methods)
- Modify: `src/Tracking.php` (new parameter)
- Modify: `src/Concerns/TracksMailable.php` (wire the declaration)
- Test: `tests/AttachmentStorageTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:
  - `OutboundMetadata::HEADER_STORE_ATTACHMENTS` (string constant, stash key `store_attachments`).
  - `Postmaster::storeAttachments(bool $store): Closure`
  - `Postmaster::storeAttachmentsWhen(Closure $resolver): static`
  - `Postmaster::resolveStoreAttachments(Email $message): ?bool` — null when no resolver registered.
  - `WithTracking::storeAttachments(): static` / `dontStoreAttachments(): static`
  - `Tracking::$storeAttachments` — `?bool`, **the last constructor parameter**.

- [ ] **Step 1: Write the failing test**

Append to `tests/AttachmentStorageTest.php`:

```php
    public function testTheAttachmentResolverReturnsNullUntilOneIsRegistered()
    {
        $message = (new \Symfony\Component\Mime\Email)->subject('Hello');

        $this->assertNull(app(\STS\Postmaster\Postmaster::class)->resolveStoreAttachments($message));
    }

    public function testTheAttachmentResolverDecidesPerMessage()
    {
        \STS\Postmaster\Facades\Postmaster::storeAttachmentsWhen(
            fn (\Symfony\Component\Mime\Email $message) => ! str_contains((string) $message->getSubject(), 'reset')
        );

        $keep = (new \Symfony\Component\Mime\Email)->subject('Your invoice');
        $skip = (new \Symfony\Component\Mime\Email)->subject('Password reset');

        $postmaster = app(\STS\Postmaster\Postmaster::class);

        $this->assertTrue($postmaster->resolveStoreAttachments($keep));
        $this->assertFalse($postmaster->resolveStoreAttachments($skip));
    }

    public function testThePerMessageOverrideTravelsAsAStashedHeader()
    {
        $message = new \Symfony\Component\Mime\Email;

        (app(\STS\Postmaster\Postmaster::class)->storeAttachments(false))($message);

        $this->assertTrue($message->getHeaders()->has(
            \STS\Postmaster\Support\OutboundMetadata::HEADER_STORE_ATTACHMENTS
        ));

        (new \STS\Postmaster\Listeners\StashOutboundMetadata)->handle(
            new \Illuminate\Mail\Events\MessageSending($message)
        );

        // Stripped from the wire, and readable from the in-process stash.
        $this->assertFalse($message->getHeaders()->has(
            \STS\Postmaster\Support\OutboundMetadata::HEADER_STORE_ATTACHMENTS
        ));
        $this->assertSame(
            '0',
            \STS\Postmaster\Support\OutboundMetadata::pull(spl_object_id($message))['store_attachments']
        );
    }

    public function testTrackingDeclaresAttachmentStorageOnAMailable()
    {
        $tracking = new \STS\Postmaster\Tracking(storeAttachments: false);

        $this->assertFalse($tracking->storeAttachments);
        $this->assertNull($tracking->storeContent);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter AttachmentStorageTest`
Expected: FAIL — `Call to undefined method …\Postmaster::resolveStoreAttachments()`.

- [ ] **Step 3: Add the courier header**

In `src/Support/OutboundMetadata.php`, add below `HEADER_STORE_CONTENT`:

```php
    const HEADER_STORE_ATTACHMENTS = 'X-Postmaster-Store-Attachments';
```

In `src/Listeners/StashOutboundMetadata.php`, add to `HEADER_MAP`:

```php
        OutboundMetadata::HEADER_STORE_ATTACHMENTS => 'store_attachments',
```

- [ ] **Step 4: Add the builder and resolver to Postmaster**

In `src/Postmaster.php`, add the property beside `$storeContentResolver`:

```php
    protected ?Closure $storeAttachmentsResolver = null;
```

And these methods after `resolveStoreContent()`:

```php
    /**
     * Build a callback that overrides attachment storage for a single message,
     * regardless of the postmaster.persistence.attachments.store setting.
     */
    public function storeAttachments(bool $store): Closure
    {
        return function (Email $message) use ($store) {
            $message->getHeaders()->addTextHeader(
                OutboundMetadata::HEADER_STORE_ATTACHMENTS, $store ? '1' : '0'
            );
        };
    }

    /**
     * Register a resolver that decides, per message, whether to store its
     * attachments — the global equivalent of the per-message
     * storeAttachments() / dontStoreAttachments() builders. Independent of the
     * content resolver, so you can keep an invoice while discarding the body
     * that carried a magic-login link.
     *
     * The closure receives the Symfony Email and must return true to store
     * attachments, false to skip them. It runs once per message, not per
     * envelope recipient.
     *
     * Precedence: a per-message storeAttachments() / dontStoreAttachments()
     * override wins; then this resolver; then the
     * postmaster.persistence.attachments.store config flag.
     */
    public function storeAttachmentsWhen(Closure $resolver): static
    {
        $this->storeAttachmentsResolver = $resolver;

        return $this;
    }

    /**
     * Decide whether to store the given message's attachments via the
     * registered resolver. Returns null when none is registered, so the caller
     * falls back to the config flag.
     */
    public function resolveStoreAttachments(Email $message): ?bool
    {
        if ($this->storeAttachmentsResolver === null) {
            return null;
        }

        return (bool) call_user_func($this->storeAttachmentsResolver, $message);
    }
```

- [ ] **Step 5: Add the fluent methods**

In `src/Concerns/WithTracking.php`, add after `dontStoreContent()`:

```php
    /**
     * Store this email's attachments, overriding the
     * persistence.attachments.store setting.
     */
    public function storeAttachments(): static
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->storeAttachments(true));
    }

    /**
     * Skip storing this email's attachments, overriding the
     * persistence.attachments.store setting. Independent of
     * dontStoreContent() — use it for mail whose attachments are large,
     * regenerable, or sensitive enough that keeping the bytes isn't worth it.
     */
    public function dontStoreAttachments(): static
    {
        return $this->withSymfonyMessage(app(Postmaster::class)->storeAttachments(false));
    }
```

Update the trait's docblock first line to name the new methods:

```php
 * Adds the fluent relatedTo() / forRecipient() / forTenant() / storeContent()
 * / dontStoreContent() / storeAttachments() / dontStoreAttachments() methods
 * to whatever it's applied to, declaring what
```

- [ ] **Step 6: Add the Tracking parameter**

In `src/Tracking.php`, append the parameter **after** `$resentFrom` — appending rather than grouping it with `$storeContent` keeps existing positional callers working:

```php
        public readonly EmailMessage|int|null $resentFrom = null,
        public readonly ?bool $storeAttachments = null,
```

Add the matching `@param` line at the end of the constructor docblock:

```php
     * @param bool|null               $storeAttachments Whether to store this
     *                                              email's attachments. null
     *                                              defers to the
     *                                              postmaster.persistence.attachments.store
     *                                              setting. Independent of
     *                                              $storeContent.
```

- [ ] **Step 7: Wire the declaration through TracksMailable**

In `src/Concerns/TracksMailable.php`, add after the `storeContent` branch:

```php
        if ($tracking->storeAttachments !== null) {
            $tracking->storeAttachments ? $this->storeAttachments() : $this->dontStoreAttachments();
        }
```

Update the trait docblock line naming the imperative methods:

```php
 * The imperative relatedTo() / forTenant() / storeContent() / dontStoreContent()
 * / storeAttachments() / dontStoreAttachments()
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter AttachmentStorageTest`
Expected: PASS (8 tests).

- [ ] **Step 9: Lint and commit**

```bash
vendor/bin/phpstan analyse
git add src/Support/OutboundMetadata.php src/Listeners/StashOutboundMetadata.php src/Postmaster.php src/Concerns src/Tracking.php tests/AttachmentStorageTest.php
git commit -m "feat: add attachment storage controls

Mirrors the three tiers content storage already has: a per-message
storeAttachments() / dontStoreAttachments() override, a global
storeAttachmentsWhen() resolver, then the config flag.

Independent of store_content, so an app can keep an invoice PDF while
discarding the body carrying a magic-login link."
```

---

### Task 3: AttachmentStore — capture, dedup, and the size cap

**Files:**
- Create: `src/Attachments/AttachmentStore.php`
- Test: `tests/AttachmentStorageTest.php`

**Interfaces:**
- Consumes: `AttachmentStatus`, `EmailAttachment` (Task 1).
- Produces:
  - `AttachmentStore::store(Email $message, string $providerMessageId, bool $storeBytes): void`
  - `AttachmentStore::usage(): int` — total bytes over *distinct checksums*.
  - `AttachmentStore::sizeOf(string $checksum): int`

- [ ] **Step 1: Write the failing test**

Append to `tests/AttachmentStorageTest.php`. Add `use Illuminate\Support\Facades\Storage;` and `use STS\Postmaster\Attachments\AttachmentStore;` and `use Symfony\Component\Mime\Email;` to the imports:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter AttachmentStorageTest`
Expected: FAIL — `Target class [STS\Postmaster\Attachments\AttachmentStore] does not exist`.

- [ ] **Step 3: Write the AttachmentStore**

`src/Attachments/AttachmentStore.php`:

```php
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
                'content_id'          => $this->contentIdOf($part),
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
        return rescue(function () use ($disk, $path, $body) {
            Storage::disk($disk)->put($path, $body);

            return [
                'status'    => AttachmentStatus::Stored,
                'disk'      => $disk,
                'path'      => $path,
                'stored_at' => now(),
            ];
        }, ['status' => AttachmentStatus::Failed]);
    }

    /**
     * The content id to record for an inline part, so resend can re-embed it
     * under the same reference the html body already points at.
     *
     * Symfony only materializes a generated cid when the message is
     * serialized, and resolves `cid:filename` references against the part's
     * filename at that point. Depending on how far the transport has gotten
     * by MessageSent, hasContentId() may still be false — so fall back to the
     * filename, which is the reference the body actually carries.
     *
     * Null for ordinary attachments: they have no cid to preserve.
     */
    protected function contentIdOf(DataPart $part): ?string
    {
        if ($part->getDisposition() !== 'inline') {
            return null;
        }

        return $part->hasContentId() ? $part->getContentId() : $part->getFilename();
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
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter AttachmentStorageTest`
Expected: PASS (14 tests).

- [ ] **Step 5: Lint and commit**

```bash
vendor/bin/phpstan analyse
git add src/Attachments/AttachmentStore.php tests/AttachmentStorageTest.php
git commit -m "feat: capture attachment bytes to a configurable disk

Content-addressed by sha256, so an embedded logo carried by every send
occupies one file rather than one per message. Oversize attachments and
a failing disk both record metadata and skip the bytes, so the record of
what was sent survives either way."
```

---

### Task 4: Wire capture into the recorder

**Files:**
- Modify: `src/Listeners/RecordOutboundMessage.php`
- Test: `tests/AttachmentStorageTest.php`, `tests/PersistenceTest.php:801-832`

**Interfaces:**
- Consumes: `AttachmentStore::store()` (Task 3), `Postmaster::resolveStoreAttachments()` (Task 2).
- Produces: attachment rows written on every real send. No new public API.

- [ ] **Step 1: Write the failing test**

Append to `tests/AttachmentStorageTest.php` (add `use Illuminate\Support\Facades\Mail;` and `use STS\Postmaster\Tests\Stubs\FullMail;`):

```php
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
        config(['postmaster.persistence.attachments.store' => true]);

        Mail::to('to@example.com')->send((new FullMail)->dontStoreAttachments());

        $this->assertSame(AttachmentStatus::NotStored, EmailAttachment::first()->status);
        $this->assertCount(0, Storage::disk('local')->allFiles());
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter AttachmentStorageTest`
Expected: FAIL — `Failed asserting that actual size 0 matches expected size 1` on `testEnvelopeSiblingsShareOneAttachmentSet`.

- [ ] **Step 3: Extract the shared flag resolution**

In `src/Listeners/RecordOutboundMessage.php`, replace the `$storeContent` block inside `sharedAttributes()` (currently lines 242-253) with a call to a shared helper:

```php
        if ($this->resolveFlag($message, $metadata, 'content')) {
            $attributes += $this->content($message);
        }

        return $attributes;
    }

    /**
     * Resolve one of the two storage switches for this message.
     *
     * Precedence, identical for both: a per-message override wins, then the
     * app-registered resolver, then the config flag. (The resolvers return
     * null when none is registered, so config is the final fallback.)
     */
    protected function resolveFlag(Email $message, array $metadata, string $which): bool
    {
        [$key, $resolved, $config] = $which === 'content'
            ? ['store_content', $this->events->resolveStoreContent($message), 'postmaster.persistence.store_content']
            : ['store_attachments', $this->events->resolveStoreAttachments($message), 'postmaster.persistence.attachments.store'];

        return isset($metadata[$key])
            ? $metadata[$key] === '1'
            : ($resolved ?? (bool) config($config, false));
    }
```

Note the `@param array<string, mixed> $metadata` docblock line on `resolveFlag`, matching the style of its neighbours.

- [ ] **Step 4: Stop writing the legacy column**

In the same file, remove `'attachments'  => $this->attachments($message),` from `content()`, delete the now-unused `attachments()` method (lines 354-367), and update `content()`'s docblock to:

```php
    /**
     * A full representation of the message — sender, recipients, and bodies.
     * Attachments are recorded separately by AttachmentStore, on their own
     * table, because they belong to the submission rather than to any one
     * envelope recipient's row.
     *
     * @return array<string, mixed>
     */
```

- [ ] **Step 5: Capture attachments in record()**

In `record()`, after `$envelope = $this->envelope($message);` and before the `foreach`, add:

```php
        $this->storeAttachments($message, $messageId, $metadata);
```

And add the method after `record()`:

```php
    /**
     * Record this submission's attachments — once, not once per envelope
     * recipient, so a To + 2 Cc send writes one set of rows that all three
     * message rows resolve through.
     *
     * Metadata lands whenever either switch is on; the bytes only when
     * attachment storage is.
     *
     * @param array<string, mixed> $metadata
     */
    protected function storeAttachments(Email $message, ?string $messageId, array $metadata): void
    {
        $storeBytes = $this->resolveFlag($message, $metadata, 'attachments');

        if (! $storeBytes && ! $this->resolveFlag($message, $metadata, 'content')) {
            return;
        }

        app(AttachmentStore::class)->store($message, (string) $messageId, $storeBytes);
    }
```

Add `use STS\Postmaster\Attachments\AttachmentStore;` to the imports.

- [ ] **Step 6: Update the two stale PersistenceTest assertions**

In `tests/PersistenceTest.php`, in `testFullMessageContentIsStoredWhenEnabled`, replace:

```php
        $this->assertSame(['invoice.pdf'], $record->attachments);
```

with:

```php
        $this->assertSame(['invoice.pdf'], $record->attachments->pluck('filename')->all());
```

In `testMessageContentIsNotStoredByDefault`, replace:

```php
        $this->assertNull($record->attachments);
```

with:

```php
        $this->assertCount(0, $record->attachments);
```

- [ ] **Step 7: Run the full suite to verify it passes**

Run: `vendor/bin/phpunit`
Expected: PASS — no failures.

- [ ] **Step 8: Lint and commit**

```bash
vendor/bin/phpstan analyse
git add src/Listeners/RecordOutboundMessage.php tests/AttachmentStorageTest.php tests/PersistenceTest.php
git commit -m "feat: record attachments on every outbound send

Capture runs once per submission rather than once per envelope
recipient, so a To plus two Cc writes one attachment set that all
three message rows resolve through.

Content and attachment switches now share one precedence helper, since
both resolve per-message override, then resolver, then config."
```

---

### Task 5: Refcount-guarded removal and the prune pass

**Files:**
- Modify: `src/Attachments/AttachmentStore.php` (removal methods)
- Modify: `src/Console/Prune.php` (flag handling, attachment pass)
- Test: `tests/AttachmentStorageTest.php`

**Interfaces:**
- Consumes: `AttachmentStore` (Task 3), `AttachmentStatus` (Task 1).
- Produces:
  - `AttachmentStore::forget(EmailAttachment $attachment, AttachmentStatus $reason): int` — bytes freed, refcount-guarded.
  - `AttachmentStore::forgetChecksum(string $checksum, AttachmentStatus $reason): int` — bytes freed, whole group.
  - `postmaster:prune --attachments`.

- [ ] **Step 1: Write the failing test**

Append to `tests/AttachmentStorageTest.php` (add `use Illuminate\Support\Facades\Artisan;`):

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter AttachmentStorageTest`
Expected: FAIL — `Call to undefined method …\AttachmentStore::forget()`.

- [ ] **Step 3: Add the removal methods to AttachmentStore**

Append to `src/Attachments/AttachmentStore.php`:

```php
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
            rescue(fn () => Storage::disk($first->disk)->delete($first->path));
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
```

- [ ] **Step 4: Rework the Prune flag handling**

In `src/Console/Prune.php`, add the flag to the signature:

```php
    protected $signature = 'postmaster:prune
                            {--content     : Only purge stored content}
                            {--activity    : Only delete old timeline activity}
                            {--attachments : Only reclaim stored attachments}
                            {--dry-run     : Report what would be pruned without writing anything}';
```

Replace `handle()`'s first four lines. The old `xor` idiom decides "did the user name exactly one pass" and doesn't extend past two flags:

```php
    public function handle(): int
    {
        $named = array_keys(array_filter([
            'content'     => (bool) $this->option('content'),
            'activity'    => (bool) $this->option('activity'),
            'attachments' => (bool) $this->option('attachments'),
        ]));

        // No flags means every pass; any flag means only the named ones.
        $runs   = $named === [] ? ['content', 'activity', 'attachments'] : $named;
        $dryRun = (bool) $this->option('dry-run');

        if (in_array('content', $runs, true)) {
            $this->pruneContent($dryRun);
        }

        if (in_array('attachments', $runs, true)) {
            $this->pruneAttachments($dryRun);
        }

        if (in_array('activity', $runs, true)) {
            $this->pruneActivity(
                'routine',
                'prune_routine_activity_after_days',
                fn ($q) => $q->whereNotIn('status', EmailMessage::FAILED_STATUSES)
                             ->orWhereNull('status'),
                $dryRun,
            );

            $this->pruneActivity(
                'failure',
                'prune_failed_activity_after_days',
                fn ($q) => $q->whereIn('status', EmailMessage::FAILED_STATUSES),
                $dryRun,
            );
        }

        return self::SUCCESS;
    }
```

Also update the command's class docblock usage lines to include:

```php
 *     php artisan postmaster:prune --attachments  # only stored attachments
```

- [ ] **Step 5: Add the attachment prune pass**

Add to `src/Console/Prune.php`, after `pruneContent()`:

```php
    /**
     * Reclaim the bytes of attachments past their retention window. The
     * metadata row survives, marked Pruned, so the dashboard can still say
     * what the email carried.
     */
    protected function pruneAttachments(bool $dryRun): void
    {
        $days = (int) config('postmaster.persistence.attachments.prune_after_days');

        if ($days <= 0) {
            $this->components->twoColumnDetail('Stored attachments', '<fg=gray>pruning disabled</>');

            return;
        }

        $expired = EmailAttachment::model()->newQuery()
            ->where('status', AttachmentStatus::Stored)
            ->where('created_at', '<', now()->subDays($days))
            ->get();

        $freed = 0;

        if (! $dryRun) {
            $store = app(AttachmentStore::class);

            foreach ($expired as $attachment) {
                $freed += $store->forget($attachment, AttachmentStatus::Pruned);
            }
        }

        $this->components->twoColumnDetail(
            'Stored attachments',
            ($dryRun ? 'would reclaim' : 'reclaimed')." {$expired->count()} "
                .Str::plural('attachment', $expired->count())
                .($dryRun ? ' <fg=gray>(dry run)</>' : ' ('.$this->bytes($freed).')')
        );
    }

    /**
     * Human-readable byte count for the report lines.
     */
    protected function bytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).$unit;
            }

            $bytes /= 1024;
        }

        return $bytes.'B';
    }
```

Add the imports:

```php
use STS\Postmaster\Attachments\AttachmentStatus;
use STS\Postmaster\Attachments\AttachmentStore;
use STS\Postmaster\Models\EmailAttachment;
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: PASS — no failures.

- [ ] **Step 7: Lint and commit**

```bash
vendor/bin/phpstan analyse
git add src/Attachments/AttachmentStore.php src/Console/Prune.php tests/AttachmentStorageTest.php
git commit -m "feat: reclaim stored attachments past their retention window

postmaster:prune --attachments removes the bytes and keeps the metadata
row, so the dashboard can still report what an email carried after the
file is gone.

Content-addressing makes deletion a reference count: a shared file
survives until its last claim is released. Reworks the command's
one-flag-or-all logic, which didn't extend past two passes."
```

---

### Task 6: Eviction under a disk budget

**Files:**
- Modify: `src/Console/Prune.php` (eviction pass)
- Test: `tests/AttachmentStorageTest.php`

**Interfaces:**
- Consumes: `AttachmentStore::usage()`, `sizeOf()`, `forgetChecksum()` (Tasks 3, 5).
- Produces: eviction behavior on `postmaster:prune`. No new public API.

- [ ] **Step 1: Write the failing test**

Append to `tests/AttachmentStorageTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter testEvictionReclaimsOldestFilesUntilUsageFitsTheBudget`
Expected: FAIL — usage is still 30; nothing was evicted.

- [ ] **Step 3: Add the eviction pass**

In `src/Console/Prune.php::handle()`, add the eviction call directly after the prune call:

```php
        if (in_array('attachments', $runs, true)) {
            $this->pruneAttachments($dryRun);
            $this->evictAttachments($dryRun);
        }
```

Add the method after `pruneAttachments()`:

```php
    /**
     * Reclaim the least-recently-referenced attachments until disk usage fits
     * the configured ceiling.
     *
     * Works on checksum *groups* rather than rows, because content-addressed
     * storage means a file is only reclaimed when every reference to it goes —
     * evicting row by row would free nothing and loop forever.
     *
     * Metadata rows survive, marked Evicted, so the dashboard reports
     * "invoice.pdf, 2.1 MB, evicted Aug 1" rather than serving a dead link.
     */
    protected function evictAttachments(bool $dryRun): void
    {
        $ceiling = (int) config('postmaster.persistence.attachments.max_disk_usage');

        if ($ceiling <= 0) {
            $this->components->twoColumnDetail('Attachment disk budget', '<fg=gray>unbounded</>');

            return;
        }

        $store    = app(AttachmentStore::class);
        $usage    = $store->usage();
        $freed    = 0;
        $count    = 0;
        $examined = [];

        while ($usage > $ceiling) {
            $checksum = EmailAttachment::model()->newQuery()
                ->where('status', AttachmentStatus::Stored)
                ->whereNotIn('checksum', $examined)
                ->groupBy('checksum')
                ->orderByRaw('max(created_at) asc')
                ->value('checksum');

            if ($checksum === null) {
                break;
            }

            // Tracked in both modes: under --dry-run nothing changes on disk,
            // so without this the same group would be selected forever.
            $examined[] = $checksum;

            $bytes = $dryRun
                ? $store->sizeOf($checksum)
                : $store->forgetChecksum($checksum, AttachmentStatus::Evicted);

            $freed += $bytes;
            $usage -= $bytes;
            $count++;
        }

        $this->components->twoColumnDetail(
            'Attachment disk budget',
            ($dryRun ? 'would evict' : 'evicted')." {$count} ".Str::plural('file', $count)
                .' ('.$this->bytes($freed).')'
                .($dryRun ? ' <fg=gray>(dry run)</>' : '')
        );
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: PASS — no failures.

- [ ] **Step 5: Lint and commit**

```bash
vendor/bin/phpstan analyse
git add src/Console/Prune.php tests/AttachmentStorageTest.php
git commit -m "feat: evict stored attachments to fit a disk budget

When attachments.max_disk_usage is set, the daily prune reclaims the
least-recently-referenced files until usage fits.

Eviction works on checksum groups rather than rows: a content-addressed
file is only reclaimed once every reference goes, so row-wise eviction
would free nothing and spin. Metadata rows survive marked Evicted, so
the record of what was sent outlives the bytes."
```

---

### Task 7: Reattach on resend and release

**Files:**
- Create: `tests/Stubs/InlineImageMail.php`
- Modify: `src/Mail/ResentMessage.php`
- Modify: `src/Mail/ReleasedMessage.php`
- Modify: `src/Models/EmailAttachment.php` (contents affordance)
- Modify: `src/Postmaster.php` (docblocks on `resend()` / `release()`)
- Modify: `src/Http/Controllers/Dashboard/MessageController.php` (flash copy, docblock)
- Test: `tests/AttachmentStorageTest.php`

**Interfaces:**
- Consumes: `EmailAttachment::isAvailable()` (Task 1), attachment rows (Task 4).
- Produces:
  - `EmailAttachment::contents(): string` — the stored bytes.
  - `EmailMessage::availableAttachments(): Collection` — the subset still retrievable.

- [ ] **Step 1: Add the inline-image stub**

`tests/Stubs/InlineImageMail.php`, following `FullMail`'s shape:

```php
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
```

- [ ] **Step 2: Write the failing test**

Append to `tests/AttachmentStorageTest.php`, adding `use STS\Postmaster\Tests\Stubs\InlineImageMail;` to the imports:

```php
    public function testResendReattachesStoredBytes()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'            => true,
            'postmaster.persistence.attachments.store'        => true,
        ]);

        Mail::to('to@example.com')->send(new FullMail);

        $sent = [];
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Mail\Events\MessageSent::class,
            function ($event) use (&$sent) { $sent[] = $event->message; }
        );

        \STS\Postmaster\Facades\Postmaster::resend(EmailMessage::first());

        $replay = end($sent);
        $parts  = $replay->getAttachments();

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
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Mail\Events\MessageSent::class,
            function ($event) use (&$sent) { $sent[] = $event->message; }
        );

        \STS\Postmaster\Facades\Postmaster::resend(EmailMessage::first());

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
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Mail\Events\MessageSent::class,
            function ($event) use (&$sent) { $sent[] = $event->message; }
        );

        \STS\Postmaster\Facades\Postmaster::resend(EmailMessage::first());

        $part = end($sent)->getAttachments()[0];

        $this->assertSame('inline', $part->getDisposition());
        $this->assertSame('logo.png', $part->getContentId());
    }
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter testResendReattachesStoredBytes`
Expected: FAIL — `Failed asserting that actual size 0 matches expected size 1`.

- [ ] **Step 4: Add the model affordances**

To `src/Models/EmailAttachment.php`:

```php
    /**
     * The stored bytes. Only call this when isAvailable() is true — a missing
     * disk or path is a bug in the caller, not a condition to swallow.
     */
    public function contents(): string
    {
        return Storage::disk($this->disk)->get($this->path);
    }
```

Add `use Illuminate\Support\Facades\Storage;` to its imports.

To `src/Models/EmailMessage.php`, beside the `attachments()` relation:

```php
    /**
     * The attachments whose bytes are still on disk. Resend and release
     * reattach these; the rest survive as metadata only.
     *
     * @return Collection<int, EmailAttachment>
     */
    public function availableAttachments(): Collection
    {
        return $this->attachments->filter->isAvailable()->values();
    }
```

- [ ] **Step 5: Reattach in ResentMessage**

In `src/Mail/ResentMessage.php`, add to `propagateContext()`'s returned closure, immediately after the `$record->text_body` block:

```php
            // Reattach whatever bytes we still hold. Inline parts go back
            // under their original CID so the html body's cid: references
            // resolve exactly as they did on the first send.
            foreach ($record->availableAttachments() as $attachment) {
                $part = new DataPart(
                    $attachment->contents(),
                    $attachment->filename,
                    $attachment->mime_type
                );

                if ($attachment->disposition === 'inline') {
                    $part->asInline();

                    if ($attachment->content_id !== null) {
                        $part->setContentId($attachment->content_id);
                    }
                }

                $message->addPart($part);
            }
```

Add `use Symfony\Component\Mime\Part\DataPart;` to the imports.

Update the class docblock, replacing the "Attachments are not restored" sentence:

```php
 * The bodies, recipients, and subject all come from the recorded row — a
 * resend therefore requires stored content. Attachments are reattached when
 * their bytes are still stored; ones that were never captured, or have since
 * been pruned or evicted, are simply left off.
```

- [ ] **Step 6: Reattach in ReleasedMessage**

`ReleasedMessage` only calls `withSymfonyMessage()` when there's a text body, so reattachment needs its own unconditional hook rather than riding along with `restoreTextAlternative()`.

In `src/Mail/ReleasedMessage.php::build()`, replace:

```php
        if ($this->record->text_body) {
            $this->withSymfonyMessage($this->restoreTextAlternative());
        }
```

with:

```php
        if ($this->record->text_body) {
            $this->withSymfonyMessage($this->restoreTextAlternative());
        }

        $this->withSymfonyMessage($this->reattachStoredAttachments());
```

Add the method after `restoreTextAlternative()`:

```php
    /**
     * Put back whatever attachment bytes we still hold. Inline parts go back
     * under their original content id, so the html body's cid: references
     * resolve exactly as they did when the message was first composed.
     *
     * Attachments never captured — or since pruned or evicted — are simply
     * left off; a release that drops an attachment still beats one that
     * refuses to go out.
     */
    protected function reattachStoredAttachments(): \Closure
    {
        $attachments = $this->record->availableAttachments();

        return function ($message) use ($attachments) {
            foreach ($attachments as $attachment) {
                $part = new DataPart(
                    $attachment->contents(),
                    $attachment->filename,
                    $attachment->mime_type
                );

                if ($attachment->disposition === 'inline') {
                    $part->asInline();

                    if ($attachment->content_id !== null) {
                        $part->setContentId($attachment->content_id);
                    }
                }

                $message->addPart($part);
            }
        };
    }
```

Add `use Symfony\Component\Mime\Part\DataPart;` to the imports, and update the class docblock, replacing:

```php
 * The bodies, recipients, subject, and tags all come from the recorded row,
 * so a release requires stored content. Attachments are not restored — the
 * package only keeps their filenames, never their bytes.
```

with:

```php
 * The bodies, recipients, subject, and tags all come from the recorded row,
 * so a release requires stored content. Attachments are reattached when their
 * bytes are still stored; ones never captured, pruned, or evicted are left
 * off.
```

- [ ] **Step 7: Correct the remaining stale claims**

In `src/Postmaster.php`, in `resend()`'s docblock replace:

```php
     * Requires the original to have stored content. Attachments are not
     * restored — the package only persists their filenames, never their
     * bytes.
```

with:

```php
     * Requires the original to have stored content. Attachments come back too
     * when their bytes are still stored — see persistence.attachments.
```

In `release()`'s docblock replace:

```php
     * The send reuses the recorded row's stored content, so a release
     * requires stored content. Attachments are not restored — the package
     * only persists their filenames, never their bytes.
```

with:

```php
     * The send reuses the recorded row's stored content, so a release
     * requires stored content. Attachments come back too when their bytes are
     * still stored — see persistence.attachments.
```

In `src/Http/Controllers/Dashboard/MessageController.php`, in `resend()`'s docblock replace:

```php
     * a "resent" tag of its own. Requires stored content; attachments are
     * not restored (we never keep their bytes).
```

with:

```php
     * a "resent" tag of its own. Requires stored content; attachments come
     * along when their bytes are still stored.
```

- [ ] **Step 8: Report skipped attachments on the resend flash**

In `MessageController::resend()`, replace the success redirect at the end of the method:

```php
        return redirect()
            ->route('postmaster.messages.show', $record)
            ->with('postmasterFlash', 'Message resent.');
    }
```

with:

```php
        // Say so when the replay couldn't carry everything the original did —
        // a silently attachment-less resend is exactly the failure this
        // feature exists to fix, so it shouldn't be invisible when it happens.
        $missing = $record->attachments->count() - $record->availableAttachments()->count();

        return redirect()
            ->route('postmaster.messages.show', $record)
            ->with('postmasterFlash', 'Message resent.'.($missing > 0
                ? " Sent without {$missing} ".Str::plural('attachment', $missing).' (no longer stored).'
                : ''));
    }
```

Add `use Illuminate\Support\Str;` to the controller's imports.

- [ ] **Step 9: Run the tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: PASS — no failures.

- [ ] **Step 10: Lint and commit**

```bash
vendor/bin/phpstan analyse
git add src/Mail src/Models src/Postmaster.php src/Http/Controllers/Dashboard/MessageController.php tests/AttachmentStorageTest.php
git commit -m "feat: reattach stored attachments on resend and release

A re-sent invoice now arrives with the invoice. Inline parts go back
under their original content id so cid: references in the body resolve
as they did on the first send.

Missing bytes never block the send -- that would break bounce recovery,
which is what resend is for. The message goes out without them and the
dashboard says how many were left off."
```

---

### Task 8: Dashboard download

**Files:**
- Modify: `routes/dashboard.php`
- Modify: `src/Http/Controllers/Dashboard/MessageController.php` (download action)
- Modify: `src/Models/EmailAttachment.php` (download affordance)
- Modify: `resources/views/message.blade.php` (attachment list)
- Test: `tests/DashboardTest.php`

**Interfaces:**
- Consumes: `EmailAttachment::isAvailable()`, `contents()` (Tasks 1, 7).
- Produces:
  - Route `postmaster.messages.attachment` at `messages/{message}/attachments/{attachment}`.
  - `EmailAttachment::download(): StreamedResponse`.

- [ ] **Step 1: Write the failing test**

Append to `tests/DashboardTest.php`, matching the file's existing setup conventions:

```php
    public function testAStoredAttachmentCanBeDownloaded()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new FullMail);

        $message    = EmailMessage::first();
        $attachment = $message->attachments->first();

        $response = $this->get("/postmaster/messages/{$message->getKey()}/attachments/{$attachment->getKey()}");

        $response->assertOk();
        $response->assertDownload('invoice.pdf');
        $this->assertSame('PDF DATA', $response->streamedContent());
    }

    public function testDownloadingAnUnavailableAttachmentIsNotFound()
    {
        Storage::fake('local');
        config(['postmaster.persistence.store_content' => true]);

        Mail::to('to@example.com')->send(new FullMail);

        $message    = EmailMessage::first();
        $attachment = $message->attachments->first();

        $this->get("/postmaster/messages/{$message->getKey()}/attachments/{$attachment->getKey()}")
            ->assertNotFound();
    }

    public function testAnAttachmentFromAnotherMessageIsNotFound()
    {
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('first@example.com')->send(new FullMail);
        $first = EmailMessage::first();

        $other = EmailAttachment::create([
            'provider_message_id' => 'unrelated',
            'filename'            => 'secret.pdf',
            'size'                => 3,
            'checksum'            => str_repeat('c', 64),
            'disposition'         => 'attachment',
            'status'              => AttachmentStatus::Stored,
            'disk'                => 'local',
            'path'                => 'postmaster/attachments/cc/cc/'.str_repeat('c', 64),
        ]);

        $this->get("/postmaster/messages/{$first->getKey()}/attachments/{$other->getKey()}")
            ->assertNotFound();
    }
```

Add whatever imports the file is missing: `Illuminate\Support\Facades\Storage`, `STS\Postmaster\Attachments\AttachmentStatus`, `STS\Postmaster\Models\EmailAttachment`, `STS\Postmaster\Tests\Stubs\FullMail`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter testAStoredAttachmentCanBeDownloaded`
Expected: FAIL — 404, the route doesn't exist.

- [ ] **Step 3: Add the model affordance**

To `src/Models/EmailAttachment.php`:

```php
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
```

Add `use Symfony\Component\HttpFoundation\StreamedResponse;` to its imports.

- [ ] **Step 4: Add the route**

In `routes/dashboard.php`, after the message `show` route:

```php
Route::get('messages/{message}/attachments/{attachment}', [MessageController::class, 'attachment'])
    ->name('postmaster.messages.attachment');
```

- [ ] **Step 5: Add the controller action**

To `src/Http/Controllers/Dashboard/MessageController.php`:

```php
    /**
     * Stream one of a message's stored attachments back to the operator.
     *
     * Scoped to the message deliberately: the attachment id alone is not
     * authority to read it, so an id from another message 404s rather than
     * leaking across records.
     */
    public function attachment(int|string $message, int|string $attachment): StreamedResponse
    {
        $record = $this->messageQuery()->findOrFail($message);

        $file = $record->attachments()->whereKey($attachment)->first();

        abort_unless($file?->isAvailable(), 404);

        return $file->download();
    }
```

Add `use Symfony\Component\HttpFoundation\StreamedResponse;` to its imports.

- [ ] **Step 6: Update the message view**

In `resources/views/message.blade.php`, replace the `@if (! empty($message->attachments))` block (currently lines 94-103) with:

```blade
            @if ($message->attachments->isNotEmpty() || $message->legacyAttachmentNames())
                <div style="margin-top: 14px;">
                    <div class="pm-stat-label">Attachments</div>
                    <ul class="pm-mono" style="margin: 6px 0 0; padding-left: 18px;">
                        @foreach ($message->attachments as $attachment)
                            <li>
                                @if ($attachment->isAvailable())
                                    <a href="{{ route('postmaster.messages.attachment', [$message, $attachment]) }}">{{ $attachment->filename }}</a>
                                @else
                                    {{ $attachment->filename }}
                                @endif
                                <span style="opacity: .6;">
                                    — {{ number_format($attachment->size / 1024, 1) }} KB@unless ($attachment->isAvailable()), {{ str_replace('_', ' ', $attachment->status->value) }}@endunless
                                </span>
                            </li>
                        @endforeach
                        @foreach ($message->legacyAttachmentNames() as $name)
                            <li>{{ $name }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
```

Then remove the two stale `title="Attachments aren't restored — only their filenames are stored."` hover attributes at lines 28 and 38.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: PASS — no failures.

- [ ] **Step 8: Lint and commit**

```bash
vendor/bin/phpstan analyse
git add routes/dashboard.php src/Http/Controllers/Dashboard/MessageController.php src/Models/EmailAttachment.php resources/views/message.blade.php tests/DashboardTest.php
git commit -m "feat: download stored attachments from the dashboard

Support can now see exactly what went out. Downloads stream through the
app behind the existing dashboard authorization rather than handing out
a cloud temporary URL, so there is one auth path and no link that
outlives the session.

Attachments are scoped to their message: an id from another record 404s
instead of leaking across messages. Unavailable ones list their status
rather than offering a dead link."
```

---

### Task 9: Documentation

**Files:**
- Modify: `readme.md`
- Modify: `config/postmaster.php` (store_content comment)

**Interfaces:**
- Consumes: everything.
- Produces: no code.

- [ ] **Step 1: Correct the content-storage callout**

In `readme.md`, in the "Storing message content" section (around line 624), replace:

```
> Attachment **contents** are never stored, only their filenames. And because
```

with:

```
> Attachment **contents** are stored separately and off by default — see
> [Storing attachments](#storing-attachments). And because
```

- [ ] **Step 2: Add the new section**

Add after the "Storing message content" section's prune paragraphs, before the section that follows it:

````markdown
### Storing attachments

Attachment metadata — filename, type, size — is recorded whenever either
storage switch is on. The bytes themselves are a separate opt-in:

```
POSTMASTER_STORE_ATTACHMENTS=true
POSTMASTER_ATTACHMENTS_DISK=s3
```

With it on, Resend and Release replay a message with its attachments intact,
and the dashboard offers each one as a download.

This is independent of `POSTMASTER_STORE_CONTENT`, which is the point: an
invoice PDF is often worth keeping when the body that carried a magic-login
link is not. Both switches take the same three-tier control, per-message
override first:

```php
return (new MailMessage)->subject('Your statement')->dontStoreAttachments();
```

```php
return new Tracking(related: $this->invoice, storeAttachments: true);
```

```php
Postmaster::storeAttachmentsWhen(
    fn ($message) => ! str_contains((string) $message->getSubject(), 'Export')
);
```

Bytes are content-addressed by sha256, so a logo embedded on every send costs
one file no matter how many messages carry it, and a file is only removed once
every message referencing it has released it.

Three limits keep the disk in hand:

```
POSTMASTER_ATTACHMENTS_MAX_SIZE=10485760          # per file; larger ones record metadata only
POSTMASTER_PRUNE_ATTACHMENTS_AFTER_DAYS=30        # retention for the bytes
POSTMASTER_ATTACHMENTS_MAX_DISK_USAGE=5368709120  # total ceiling; evicts oldest first
```

The daily prune enforces the last two. Either way the metadata row survives, so
the dashboard still reports what an email carried:

```bash
php artisan postmaster:prune --attachments
php artisan postmaster:prune --attachments --dry-run
```

> An attachment whose bytes are gone — never stored, oversize, pruned, or
> evicted — is listed with its status instead of a download link. Resend and
> Release send without it rather than refusing, and say how many were left off.
````

- [ ] **Step 3: Correct the remaining stale readme passages**

Search for the two remaining claims and fix them:

At line ~712 (the resend section), replace:

```
Attachments are not restored — the package only persists their filenames,
```

with:

```
Attachments are restored when their bytes are stored; otherwise the package
only has their filenames,
```

At line ~1047 (the release/API reference line), replace:

```
  delivered. Requires stored content; attachments are not restored.
```

with:

```
  delivered. Requires stored content; attachments come along when stored.
```

- [ ] **Step 4: Correct the config comment**

In `config/postmaster.php`, in the `store_content` comment block, replace:

```php
         * Store the full message content (sender, recipients, bodies, and
         * attachment filenames) on each record. Off by default: message
```

with:

```php
         * Store the full message content (sender, recipients, and bodies) on
         * each record, plus attachment metadata. Attachment *bytes* are a
         * separate opt-in — see the attachments block below. Off by default:
         * message
```

- [ ] **Step 5: Verify no stale claims remain**

Run: `grep -rn "not restored\|never stored, only their filenames\|never keep their bytes" readme.md src/ resources/ config/`
Expected: no output.

- [ ] **Step 6: Run the full suite and lint**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse`
Expected: PASS, no errors.

- [ ] **Step 7: Commit**

```bash
git add readme.md config/postmaster.php
git commit -m "docs: document attachment storage

Adds a Storing attachments section and corrects every remaining claim
that the package never keeps attachment bytes."
```

---

## Verification

After Task 9, confirm the whole feature end to end:

- [ ] `vendor/bin/phpunit` — full suite green
- [ ] `vendor/bin/phpstan analyse` — no errors
- [ ] `git log --oneline` shows nine feature commits
- [ ] `grep -rn "not restored" readme.md src/ resources/` returns nothing
