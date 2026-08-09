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
