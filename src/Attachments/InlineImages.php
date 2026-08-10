<?php

namespace STS\Postmaster\Attachments;

use Illuminate\Support\Collection;
use STS\Postmaster\Models\EmailAttachment;

/**
 * Resolves the `cid:` references in a stored email body into inline data URIs,
 * so the dashboard preview shows the logo the recipient saw instead of a
 * broken-image icon.
 *
 * `cid:` means nothing to a browser — it's a mail-client scheme — so before
 * attachment storage existed there was no way to render these at all. Now that
 * we hold the bytes, they can be substituted directly into the preview.
 *
 * Data URIs rather than links to the download route, for two reasons: the
 * preview CSP already permits `img-src data:`, so nothing has to be relaxed;
 * and the preview iframe is sandboxed without allow-same-origin, giving it an
 * opaque origin that `'self'` would never match.
 */
class InlineImages
{
    /**
     * Largest inline image to embed, in bytes. Base64 inflates by a third and
     * this lands inside an HTML attribute, so a 10 MB image — which
     * attachments.max_size permits — would produce a ~13 MB srcdoc. Inline
     * parts are logos and signatures in practice; a megabyte is generous.
     */
    public const MAX_INLINE_SIZE = 1048576;

    /**
     * Replace every resolvable `cid:` reference in the body with the image it
     * points at. Anything unresolvable is left alone for hasUnresolved() to
     * report.
     *
     * @param Collection<int, EmailAttachment> $attachments
     */
    public function resolve(?string $html, Collection $attachments): ?string
    {
        if ($html === null || ! str_contains($html, 'cid:')) {
            return $html;
        }

        $inline = $attachments
            ->filter(fn (EmailAttachment $a) => $a->disposition === 'inline'
                && $a->isAvailable()
                && $a->size <= self::MAX_INLINE_SIZE)
            // Longest filename first: replacing "cid:logo.png" before
            // "cid:logo.png.bak" would corrupt the longer reference.
            ->sortByDesc(fn (EmailAttachment $a) => strlen($a->filename));

        foreach ($inline as $attachment) {
            $html = str_replace(
                "cid:{$attachment->filename}",
                $this->dataUri($attachment),
                $html
            );
        }

        return $html;
    }

    /**
     * Whether any `cid:` reference survived resolution — an embedded image
     * that was never captured, or has since been pruned, evicted, or judged
     * too large to inline. The preview says so out loud rather than leaving a
     * broken icon that reads as though the email itself went out broken.
     */
    public function hasUnresolved(?string $html): bool
    {
        return is_string($html) && str_contains($html, 'cid:');
    }

    /**
     * Plain string replacement rather than a regex: it covers every quoting
     * style and CSS url() form for free, and avoids escaping a filename we
     * don't control. The "cid:" prefix keeps one filename from matching
     * inside another.
     */
    protected function dataUri(EmailAttachment $attachment): string
    {
        return 'data:'.($attachment->mime_type ?: 'application/octet-stream')
            .';base64,'.base64_encode($attachment->contents());
    }
}
