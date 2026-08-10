<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailAttachment;
use STS\Postmaster\Models\EmailMessage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The inbox: a filterable, cross-tenant list of recorded messages, and the
 * per-message detail view with its delivery timeline and stored content.
 */
class MessageController extends Controller
{
    public function index(Request $request): Response
    {
        // filled() rather than a truthy check on the value: a tenant keyed "0"
        // is a real tenant, and so is a tag named "0".
        $query = $this->messageQuery()->withFileAttachmentCount()->latest()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->query('provider')))
            ->when($request->filled('tenant'), fn ($q) => $q->where(EmailMessage::tenantColumn(), $request->query('tenant')))
            ->when($request->filled('tag'), fn ($q) => $q->taggedWith($request->query('tag')));

        $this->applyContains($query, 'to_address', $request->query('to'));
        $this->applyContains($query, 'subject', $request->query('subject'));
        $this->applyDateRange($query, 'created_at', $request->query('date_from'), $request->query('date_to'));

        return response()->view('postmaster::messages', [
            'messages'   => $query->paginate(50)->withQueryString(),
            'filters'    => $request->query(),
            'statuses'   => $this->statuses(),
            'providers'  => $this->providersInUse(),
            'tags'       => $this->tagsInUse(),
            'tenants'    => $this->tenantLabels($this->tenantKeysInUse()),
            'tenantTerm' => $this->tenantTerm(),
        ]);
    }

    public function show(int|string $message): Response
    {
        $record = $this->messageQuery()->findOrFail($message);

        // Sibling rows for the same outbound submission — when the email
        // went to multiple envelope recipients each got its own row, all
        // sharing this row's provider_message_id.
        $siblings = $record->provider_message_id
            ? $this->messageQuery()
                ->where('provider_message_id', $record->provider_message_id)
                ->where('id', '!=', $record->getKey())
                ->orderByRaw("CASE recipient_role WHEN 'to' THEN 1 WHEN 'cc' THEN 2 ELSE 3 END")
                ->orderBy('id')
                ->get()
            : collect();

        // The resend chain: every message that's part of this row's
        // resend lineage (the original, any resends of it, resends of
        // those, ordered by sent_at). Empty collection — not even a
        // self-row — when this message is neither a resend nor has
        // resends, so the view can simply check isNotEmpty() to render.
        $chain = ($record->resent_from_id || $record->resends()->exists())
            ? $record->resendChain()
            : collect();

        return response()->view('postmaster::message', [
            'message'        => $record,
            'activity'       => $record->activity()->get(),
            'siblings'       => $siblings,
            'chain'          => $chain,
            'tenants'        => $this->tenantLabels([$record->tenantKey()]),
            'tenantTerm'     => $this->tenantTerm(),
            'recipientLabel' => $this->labelForRecipientOnRecord($record),
            'canResend'      => $this->canResend($record),
            'canRelease'     => $this->canRelease($record),
            // Remote images are blocked by the preview CSP. The viewer can
            // opt in per view with ?images=1; the bar is offered only when
            // the message actually has a remote image to unblock.
            'showImages'      => request()->boolean('images'),
            'hasRemoteImages' => $this->hasRemoteImages($record->html_body),
        ]);
    }

    /**
     * What Resend and Release both require of a message: content to replay,
     * somewhere to replay it to, and a recipient we haven't locally
     * suppressed. The dashboard holds that last line deliberately — an
     * operator should lift a suppression on purpose rather than around it.
     * App code can still call EmailMessage::resend() directly; this is the
     * dashboard's own UX choice.
     */
    protected function isReplayable(EmailMessage $record): bool
    {
        return $record->hasStoredContent()
            && $record->to_address
            && ! $record->recipientIsSuppressed();
    }

    /**
     * Whether the Resend button should render on this row. Gated on the
     * message itself, not the global delivery mode.
     *
     * A sandboxed message is excluded: it was never actually sent, so its
     * action is Release — which sends this very message — where a resend
     * would replay it as a separate new one.
     *
     * A message that was released (and so is genuinely sent) is resendable
     * like any other sent message, even while sandbox mode is on globally —
     * the resend is simply itself sandboxed, then releasable in turn.
     */
    protected function canResend(EmailMessage $record): bool
    {
        return ! $record->isSandboxed() && $this->isReplayable($record);
    }

    /**
     * Whether the Release button should render on this row. True only for a
     * still-sandboxed message that is otherwise replayable. Once released the
     * row is no longer sandboxed, so the button naturally disappears and
     * can't fire twice.
     */
    protected function canRelease(EmailMessage $record): bool
    {
        return $record->isSandboxed() && $this->isReplayable($record);
    }

    /**
     * Stream one of a message's stored attachments back to the operator.
     *
     * Scoped to the message deliberately: the attachment id alone is not
     * authority to read it, so an id from another message 404s rather than
     * leaking across records.
     */
    public function attachment(int|string $message, int|string $attachment): StreamedResponse|RedirectResponse
    {
        $record = $this->messageQuery()->findOrFail($message);

        /** @var EmailAttachment|null $file */
        $file = $record->attachments()->whereKey($attachment)->first();

        abort_unless($file?->isAvailable(), 404);

        return $file->download();
    }

    /**
     * Resend a previously recorded email — typically after a bounce, once
     * the recipient has corrected their address. The replay carries over
     * subject, sender, recipients, bodies, and the tracking context, plus
     * a "resent" tag of its own. Requires stored content; attachments come
     * along when their bytes are still stored.
     */
    public function resend(int|string $message): RedirectResponse
    {
        $record = $this->messageQuery()->findOrFail($message);

        if (! $this->canResend($record)) {
            return $this->backToMessage($record, $this->resendBlockedReason($record), failed: true);
        }

        if ($this->throttled('resend', $record)) {
            return $this->backToMessage(
                $record,
                "Already resent in the last {$this->throttleSeconds()}s. Try again shortly.",
                failed: true,
            );
        }

        // Say so when the replay couldn't carry everything the original did —
        // a silently attachment-less resend is exactly the failure this
        // feature exists to fix, so it shouldn't be invisible when it happens.
        return $this->send(
            $record,
            'resend',
            fn () => Postmaster::resend($record),
            'Message resent.'.$this->missingAttachmentNote($record),
        );
    }

    /**
     * Release a sandboxed message: send it for real and flip the record to
     * "sent". Sandbox delivery recorded it but never handed it to a provider;
     * this is the deliberate opt-out for a single message. See
     * Postmaster::release() for the mechanics.
     */
    public function release(int|string $message): RedirectResponse
    {
        $record = $this->messageQuery()->findOrFail($message);

        if (! $this->canRelease($record)) {
            return $this->backToMessage($record, $this->releaseBlockedReason($record), failed: true);
        }

        if ($this->throttled('release', $record)) {
            return $this->backToMessage($record, 'Already releasing this message. Give it a moment.', failed: true);
        }

        return $this->send(
            $record,
            'release',
            fn () => Postmaster::release($record),
            "Released — {$record->to_address} was sent for real.",
        );
    }

    /**
     * Hand a message to the mailer and turn either outcome into a flash.
     *
     * Handing it over is a genuine external boundary — there may be no working
     * mail provider wired up, or its credentials may be refused — and this
     * runs after the eligibility gate, so a throw here is the provider's news,
     * not a bug in the request. Report it, release the throttle so the
     * operator can retry once mail works, and say what happened instead of
     * 500ing. Neither action mutates the row itself, so a failure leaves the
     * message exactly as it was.
     *
     * @param Closure(): mixed $send
     */
    protected function send(EmailMessage $record, string $action, Closure $send, string $success): RedirectResponse
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
            $this->clearThrottle($action, $record);

            return $this->backToMessage(
                $record,
                ucfirst($action)." failed — the email could not be sent. {$e->getMessage()}",
                failed: true,
            );
        }

        return $this->backToMessage($record, $success);
    }

    /**
     * Claim this message's throttle slot for the action, reporting true when
     * it was already taken — a double-click, or a rapid-fire "oops".
     *
     * Cache::add() claims and tests in one step. Asking first and writing
     * after leaves a gap two simultaneous clicks can both pass through, which
     * is precisely the case this guards.
     */
    protected function throttled(string $action, EmailMessage $record): bool
    {
        $seconds = $this->throttleSeconds();

        return $seconds > 0 && ! Cache::add($this->throttleKey($action, $record), true, $seconds);
    }

    protected function clearThrottle(string $action, EmailMessage $record): void
    {
        Cache::forget($this->throttleKey($action, $record));
    }

    protected function throttleKey(string $action, EmailMessage $record): string
    {
        return "postmaster.{$action}.{$record->getKey()}";
    }

    protected function throttleSeconds(): int
    {
        return (int) config('postmaster.dashboard.resend_throttle_seconds', 60);
    }

    /**
     * The reason a resend was refused, for the flash message.
     */
    protected function resendBlockedReason(EmailMessage $record): string
    {
        return match (true) {
            $record->isSandboxed()        => "Can't resend — this message was sandboxed and never sent. Release it instead.",
            ! $record->hasStoredContent() => "Can't resend — no stored content. Enable POSTMASTER_STORE_CONTENT for future messages.",
            default                       => "Can't resend — {$record->to_address} is suppressed. Unsuppress the address first.",
        };
    }

    /**
     * The reason a release was refused, for the flash message.
     */
    protected function releaseBlockedReason(EmailMessage $record): string
    {
        return match (true) {
            ! $record->isSandboxed()      => "Can't release — this message isn't sandboxed (it may already have been released).",
            ! $record->hasStoredContent() => "Can't release — no stored content to send. Content storage was off when it was sandboxed.",
            default                       => "Can't release — {$record->to_address} is suppressed. Unsuppress the address first.",
        };
    }

    /**
     * The trailing note naming attachments a replay can no longer carry, or
     * an empty string when it carried everything.
     */
    protected function missingAttachmentNote(EmailMessage $record): string
    {
        $missing = $record->missingAttachmentCount();

        return $missing > 0
            ? " Sent without {$missing} ".Str::plural('attachment', $missing).' (no longer stored).'
            : '';
    }

    /**
     * Back to the message with a flash. Every outcome of Resend and Release
     * lands here, so the two read as one shape rather than as eight redirects.
     */
    protected function backToMessage(EmailMessage $record, string $message, bool $failed = false): RedirectResponse
    {
        return redirect()
            ->route('postmaster.messages.show', $record)
            ->with($failed ? 'postmasterError' : 'postmasterFlash', $message);
    }

    /**
     * Delete a message from the stored history — for scrubbing PII or removing
     * a record that should never have been kept. This only removes Postmaster's
     * record (the row and its timeline); it does not recall or unsend an email
     * that already went out. Other envelope recipients of the same email are
     * separate records and are left untouched.
     */
    public function destroy(int|string $message): RedirectResponse
    {
        $record = $this->messageQuery()->findOrFail($message);

        // The model's deleting hook removes the message's timeline activity;
        // resent_from_id is ON DELETE SET NULL, so any resends just lose their
        // link rather than being deleted too.
        $record->delete();

        return redirect()
            ->route('postmaster.messages')
            ->with('postmasterFlash', 'Message deleted from your history.');
    }

    /**
     * Every message recorded against a single recipient-model — the "person
     * view." The morph type is taken straight from the URL (any morph map
     * the app registered applies), so existing morph aliases work without
     * extra wiring.
     */
    public function forRecipient(string $type, int|string $id): Response
    {
        $messages = $this->messageQuery()
            ->where('recipient_type', $type)
            ->where('recipient_id', $id)
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $recipient = $this->loadRecipient($type, $id);

        return response()->view('postmaster::recipient', [
            'messages'   => $messages,
            'label'      => $recipient ? $this->recipientLabel($recipient) : class_basename($type).' #'.$id,
            'type'       => $type,
            'id'         => $id,
            'tenants'    => $this->tenantLabels($messages->pluck(EmailMessage::tenantColumn())->all()),
            'tenantTerm' => $this->tenantTerm(),
        ]);
    }

    /**
     * Try to load the recipient model behind a (type, id) pair so we can
     * label it. Returns null when the type does not resolve, the row no
     * longer exists, or persistence is using a different connection — the
     * person view still works in any of those cases, just without a name.
     */
    protected function loadRecipient(string $type, int|string $id): ?Model
    {
        $class = $this->resolveMorphClass($type);

        if ($class === null) {
            return null;
        }

        return (new $class)->newQuery()->withoutGlobalScopes()->whereKey($id)->first();
    }

    /**
     * The recipient-model label for the message detail page, or null when
     * the message has no recipient model on file.
     */
    protected function labelForRecipientOnRecord(EmailMessage $record): ?string
    {
        if (empty($record->recipient_type) || empty($record->recipient_id)) {
            return null;
        }

        $recipient = $this->loadRecipient($record->recipient_type, $record->recipient_id);

        return $recipient
            ? $this->recipientLabel($recipient)
            : class_basename($record->recipient_type).' #'.$record->recipient_id;
    }

    /**
     * Whether the HTML contains an <img> with a remote (non-data:) source —
     * i.e. an image the preview CSP would block.
     */
    protected function hasRemoteImages(mixed $html): bool
    {
        return is_string($html)
            && preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']?\s*(?:https?:)?\/\//i', $html) === 1;
    }

    /**
     * Distinct provider names present in the messages table. Providers are
     * stored under their display name ("SendGrid"), so the filter options
     * must come from the data — not the lower-case config keys.
     *
     * @return array<int, string>
     */
    protected function providersInUse(): array
    {
        return $this->messageQuery()
            ->whereNotNull('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->all();
    }

    /**
     * The distinct tags present across recorded messages, for the filter.
     * Tags live in a JSON array column, so they are flattened in PHP rather
     * than with a database-specific distinct.
     *
     * @return array<int, string>
     */
    protected function tagsInUse(): array
    {
        return $this->messageQuery()
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
