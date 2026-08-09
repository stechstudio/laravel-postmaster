# Attachment storage

Store the bytes of outbound email attachments, not just their filenames, with
the same three-tier control surface that already governs message content.

## Why

The package records attachment *filenames* and discards their contents. That
costs us two things:

1. **Resend and release are unfaithful.** Both replay a recorded row through
   the mailer. A re-sent invoice arrives with no invoice attached — the exact
   support scenario Resend exists for.
2. **Support can't see what went out.** "Did the statement actually go with
   it?" is unanswerable from the dashboard.

The original rationale was size and privacy. Neither survives scrutiny. Size is
an operations problem with ordinary operations answers — a retention window on
local disk, or cloud storage where it stops being a question. Privacy is real
but not *differential*: message bodies carry PII more often than attachments
do, and the package already ships a precise opt-out for bodies. Attachments
deserve the same mechanism rather than a blanket refusal.

## Scope

Two jobs, both in scope:

- **Resend/release fidelity** — reattach the real bytes when replaying.
- **Support/audit visibility** — download from the dashboard exactly what went
  out.

Explicitly *not* a compliance-retention feature. Retention stays short and
operator-tunable; nothing here claims immutability or a legal hold.

## Data model

### New table: `email_attachments`

Name configurable via `postmaster.persistence.attachments_table`, matching the
existing `messages_table` convention.

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `provider_message_id` | string, indexed | Grouping key. Envelope siblings (To/Cc/Bcc) share one provider id, so all of a message's recipient rows resolve the same attachment set. This is the same key `reconcileRelease()` already groups siblings by. |
| `filename` | string | |
| `mime_type` | string, nullable | |
| `size` | unsignedBigInteger | Byte length, recorded even when bytes aren't stored. |
| `checksum` | string(64), indexed | sha256 of the contents. The dedup and refcount key. |
| `disposition` | string | `attachment` or `inline` — Symfony's own vocabulary. |
| `content_id` | string, nullable | CID for inline parts, so re-embedding on resend is faithful. |
| `disk` | string, nullable | Which disk the bytes landed on, recorded per row so changing config later doesn't orphan existing files. |
| `path` | string, nullable | Null whenever `status !== stored`. |
| `status` | string | Backed enum, below. |
| `stored_at` | timestamp, nullable | |
| `created_at` / `updated_at` | timestamps | `created_at` drives both retention and eviction ordering. |

Index on `checksum` (refcount lookups), on `provider_message_id` (the
relation), and on `created_at` (prune and eviction scans).

### `AttachmentStatus` enum

| Case | Meaning |
|---|---|
| `Stored` | Bytes are on disk at `path`. |
| `NotStored` | Metadata recorded by policy; bytes never captured because attachment storage was off for this message. |
| `Oversize` | Exceeded `max_size`. Metadata recorded, bytes skipped. |
| `Pruned` | Bytes removed by the retention window. |
| `Evicted` | Bytes removed to stay under the disk budget. |
| `Failed` | The disk write raised. Metadata recorded, exception reported. |

Only `Stored` rows count toward disk usage or hold a live `path`.

### Content-addressed storage

Path layout: `{path_prefix}/{ab}/{cd}/{sha256}`, where `ab` and `cd` are the
first two byte-pairs of the checksum — two levels of fan-out so no directory
holds millions of entries.

Before writing, look for an existing `Stored` row with the same checksum. If
one exists, reuse its `disk` and `path` and skip the write entirely. An
embedded logo on 400,000 sends is written once and referenced 400,000 times.

**Deletion is refcount-guarded.** A file is unlinked only when no other
`Stored` row shares its checksum — one indexed count query.

**Disk usage is measured over distinct checksums**, not summed rows.
`SELECT SUM(size) FROM (SELECT DISTINCT checksum, size ... WHERE status =
'stored')`. Summing rows would report 400,000 × 40 KB of logo usage that no
amount of row deletion can reclaim, and eviction would spin without freeing
anything.

### Relation and the legacy column

`EmailMessage hasMany EmailAttachment` on `provider_message_id` →
`provider_message_id`.

The existing `attachments` JSON column collides with this relation: Eloquent
resolves attributes before relations, so a column named `attachments` makes
`$message->attachments` unreachable as a relation. **A migration renames the
existing column to `legacy_attachment_names`**, freeing the name. Laravel 11+
renames columns natively, so no `doctrine/dbal` dependency. `Prune::pruneContent()`
updates to the new column name.

The new table is the single source of truth for attachment metadata going
forward, written whenever *either* switch is on — `Stored` rows when attachment
storage is on, `NotStored` rows when only content storage is. When both are
off, no attachment rows are written at all.

The legacy column is no longer written. `EmailMessage`'s `'attachments' =>
'array'` cast moves to `'legacy_attachment_names' => 'array'`, and
`EmailMessage::legacyAttachmentNames()` reads it for pre-upgrade rows; the
dashboard falls back to it when the relation is empty. `PersistenceTest`'s
existing assertions on `$record->attachments` (currently a flat filename array)
move to the relation.

## Control surface

### Config

A nested block under `persistence`. This departs from the flat keys there
today, but six related settings earn the grouping.

```php
'attachments' => [
    'store'            => env('POSTMASTER_STORE_ATTACHMENTS', false),
    'disk'             => env('POSTMASTER_ATTACHMENTS_DISK', 'local'),
    'path'             => 'postmaster/attachments',
    'max_size'         => env('POSTMASTER_ATTACHMENTS_MAX_SIZE', 10 * 1024 * 1024),
    'max_disk_usage'   => env('POSTMASTER_ATTACHMENTS_MAX_DISK_USAGE'),   // null = unbounded
    'prune_after_days' => env('POSTMASTER_PRUNE_ATTACHMENTS_AFTER_DAYS', 30),
],
```

Off by default, matching `store_content`.

### Three tiers, same precedence as content

Per-message override wins, then the global resolver, then the config flag —
identical to `resolveStoreContent()`'s precedence, so there is nothing new to
learn.

- `WithTracking::storeAttachments()` / `dontStoreAttachments()` — on Mailables
  and `MailMessage` alike, alongside the existing content methods.
- `Tracking` gains `?bool $storeAttachments = null`. **Appended after
  `$resentFrom`**, not inserted next to `$storeContent`, so positional callers
  don't break.
- `TracksMailable` wires the declaration through, mirroring its `storeContent`
  branch.
- `Postmaster::storeAttachments(bool): Closure` and
  `Postmaster::storeAttachmentsWhen(Closure)`, with
  `resolveStoreAttachments(Email): ?bool` returning null when no resolver is
  registered.
- `OutboundMetadata::HEADER_STORE_ATTACHMENTS`, mapped in
  `StashOutboundMetadata` and stripped before transmission like its siblings.

### Independence from `store_content`

The two switches are independent, which makes four states legal — including
"attachments on, bodies off," the case that motivates the feature: keep the
invoice, discard the body carrying the magic link.

That state cannot resend, because there is no body to replay. `Postmaster::resend()`
already throws for missing content and keeps that behavior unchanged; the
dashboard shows the attachments and explains why Resend is unavailable. Nothing
silently half-works.

## Lifecycle

### Capture

`STS\Postmaster\Attachments\AttachmentStore`, invoked once per `record()` call
— not once per recipient row, so four envelope siblings produce one write and
one set of rows.

```php
store(Email $message, string $providerMessageId, bool $storeBytes): void
forget(iterable $attachments): int   // refcount-guarded unlink, returns bytes freed
usage(): int                          // distinct-checksum byte total
```

`store()` is called only when at least one of the two switches resolved on;
`$storeBytes` then decides `Stored` versus `NotStored` rows.

It earns being a class rather than an inlined loop: iterate parts, checksum,
cap-check, dedup lookup, disk write, row insert. It is called from the listener
and the prune command — both domain classes. No UI code touches it.

Inline and attached parts are both captured; checksum dedup is what makes that
affordable.

### Failure handling

`MessageSent` fires *after* the send. A disk failure cannot unsend anything and
must not blow up the request.

The disk write is a genuine external boundary and gets `rescue()` — the row
lands with status `Failed` and the exception reports. The database writes around
it are ours and stay unwrapped, failing loudly.

### Prune

`postmaster:prune --attachments`, alongside the existing flags.

Rows older than `prune_after_days` flip to `Pruned` with `path` and `disk`
nulled; each affected checksum then unlinks only if no `Stored` reference
survives.

This requires reworking `Prune::handle()`'s `$only = ($this->option('content')
xor $this->option('activity'))` line, which doesn't extend to a third flag.
Replacement: count the set flags; run every pass when none are set, otherwise
run only those named. Contained, and directly in the path of this work.

### Eviction

A second pass in the same command, active only when `max_disk_usage` is set.

While usage exceeds the ceiling, take the least-recently-referenced checksum
group (`GROUP BY checksum ORDER BY MAX(created_at) ASC`), unlink its file, and
mark every referencing row `Evicted` with a timestamp.

Eviction operates on checksum groups rather than rows because a shared file
frees nothing until its last reference goes — row-wise eviction would loop
without reclaiming space.

Metadata rows survive eviction. The dashboard shows "invoice.pdf — 2.1 MB,
evicted Aug 1" rather than a dead link, so the record of *what was sent* stays
intact even when the bytes don't.

Honors `--dry-run` and reports bytes reclaimed, matching the existing passes.

### Resend and release

`ResentMessage::build()` and `ReleasedMessage` reattach every `Stored`
attachment via `attachData()`, re-embedding inline parts under their original
`content_id`.

Missing bytes do not block the send. Refusing would break bounce recovery,
which is Resend's entire purpose. The send proceeds without them and the
dashboard flash reports "resent without 2 attachments (no longer stored)."

Reading a whole file into memory is acceptable given `max_size` bounds each one
at 10 MB by default.

### Dashboard

The attachment list on the message page gains size, status, and a download
link, behind the existing `AuthorizeDashboard` middleware.

Downloads stream through the app via `Storage::disk($a->disk)->download()`
rather than handing out an S3 temporary URL — one authorization path, and no
link that outlives the session. `EmailAttachment::download()` is the model
affordance the controller calls; the controller never touches `Storage` or
`AttachmentStore` directly.

`EmailAttachment::isAvailable()` gates the link: `status === Stored` and `path`
present.

## Dependency

`composer.json` gains `illuminate/filesystem` (`^12.0|^13.0`) in `require`. The
package uses no filesystem today. It is always present in a real Laravel app,
so declaring it costs nothing and is honest about what the code needs.

## Testing

New `tests/AttachmentStorageTest.php`, on `Storage::fake()`:

- All three precedence tiers: per-message override, resolver, config flag.
- Dedup writes one file across two messages with identical content.
- Envelope siblings (To + 2 Cc) produce one attachment row set, not three.
- Oversize records metadata with status `Oversize` and no bytes.
- Prune unlinks only when the last reference goes; a shared file survives a
  partial prune.
- Eviction respects shared files, marks every referencing row, and reclaims the
  expected byte count.
- A failing disk marks `Failed` and reports without throwing.
- Legacy `legacy_attachment_names` rows still render.

Additions to `DashboardTest`: the download route serves stored bytes, refuses
unavailable ones, and enforces `AuthorizeDashboard`.

Additions to `PersistenceTest`: `NotStored` rows are written when content
storage is on but attachment storage is off.

## Documentation

A "Storing attachments" section in `readme.md` following the existing "Storing
message content" section.

Every place that currently states attachments are never restored has to be
corrected: `Postmaster::resend()`, `Postmaster::release()`, `ResentMessage`,
`ReleasedMessage`, `RecordOutboundMessage::content()` and `::attachments()`,
the `message.blade.php` hover titles, the `store_content` config comment, and
the three readme passages (the content-storage callout, the resend section, and
the release section).
