<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;

/**
 * The inbox: a filterable, cross-tenant list of recorded messages, and the
 * per-message detail view with its delivery timeline and stored content.
 */
class MessageController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $this->messageQuery()->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($provider = $request->query('provider')) {
            $query->where('provider', $provider);
        }

        $tenant = $request->query('tenant');

        if ($tenant !== null && $tenant !== '') {
            $query->where(EmailMessage::tenantColumn(), $tenant);
        }

        if ($tag = $request->query('tag')) {
            $query->taggedWith($tag);
        }

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
            'tenants'        => $this->tenantLabels([$record->{EmailMessage::tenantColumn()}]),
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
     * Whether the Resend button should render on this row. Gated on the
     * message itself, not the global delivery mode. False when:
     *   - the message is sandboxed (never actually sent — its action is
     *     Release, which sends this one for real; a resend would instead
     *     replay it as a separate new message), or
     *   - there's no stored content to replay, or
     *   - the recipient is currently suppressed locally (the dashboard
     *     wants the operator to clear the suppression intentionally before
     *     re-sending). Operator can still resend via the EmailMessage::resend()
     *     API from their own code — this is just the dashboard's UX choice.
     *
     * A message that was released (and so is genuinely sent) is resendable
     * like any other sent message, even while sandbox mode is on globally —
     * the resend is simply itself sandboxed, then releasable in turn.
     */
    protected function canResend(EmailMessage $record): bool
    {
        if ($record->isSandboxed()) {
            return false;
        }

        if (! $record->html_body && ! $record->text_body) {
            return false;
        }

        if (! $record->to_address) {
            return false;
        }

        $address = EmailAddress::model()->newQuery()
            ->where('address', EmailAddress::normalize($record->to_address))
            ->first();

        return ! $address || ! $address->isSuppressed();
    }

    /**
     * Whether the Release button should render on this row. True only for a
     * still-sandboxed message that has stored content to send and whose
     * recipient isn't locally suppressed. Once released the row is no longer
     * sandboxed, so the button naturally disappears and can't fire twice.
     */
    protected function canRelease(EmailMessage $record): bool
    {
        if (! $record->isSandboxed()) {
            return false;
        }

        if (! $record->html_body && ! $record->text_body) {
            return false;
        }

        if (! $record->to_address) {
            return false;
        }

        $address = EmailAddress::model()->newQuery()
            ->where('address', EmailAddress::normalize($record->to_address))
            ->first();

        return ! $address || ! $address->isSuppressed();
    }

    /**
     * Resend a previously recorded email — typically after a bounce, once
     * the recipient has corrected their address. The replay carries over
     * subject, sender, recipients, bodies, and the tracking context, plus
     * a "resent" tag of its own. Requires stored content; attachments are
     * not restored (we never keep their bytes).
     */
    public function resend(Request $request, int|string $message): RedirectResponse
    {
        $record = $this->messageQuery()->findOrFail($message);

        if (! $this->canResend($record)) {
            return redirect()
                ->route('postmaster.messages.show', $record)
                ->with(
                    'postmasterError',
                    (! $record->html_body && ! $record->text_body)
                        ? "Can't resend — no stored content. Enable POSTMASTER_STORE_CONTENT for future messages."
                        : "Can't resend — {$record->to_address} is suppressed. Unsuppress the address first."
                );
        }

        // Rate-limit duplicate resends of the same message — guards against
        // double-clicks and rapid-fire "oops" scenarios.
        $throttleSeconds = (int) config('postmaster.dashboard.resend_throttle_seconds', 60);
        $cacheKey = "postmaster.resend.{$record->getKey()}";

        if ($throttleSeconds > 0 && Cache::has($cacheKey)) {
            return redirect()
                ->route('postmaster.messages.show', $record)
                ->with('postmasterError', "Already resent in the last {$throttleSeconds}s. Try again shortly.");
        }

        if ($throttleSeconds > 0) {
            Cache::put($cacheKey, true, now()->addSeconds($throttleSeconds));
        }

        Postmaster::resend($record);

        return redirect()
            ->route('postmaster.messages.show', $record)
            ->with('postmasterFlash', 'Message resent.');
    }

    /**
     * Release a sandboxed message: send it for real and flip the record to
     * "sent". Sandbox delivery recorded it but never handed it to a provider;
     * this is the deliberate opt-out for a single message. See
     * Postmaster::release() for the mechanics.
     */
    public function release(Request $request, int|string $message): RedirectResponse
    {
        $record = $this->messageQuery()->findOrFail($message);

        if (! $this->canRelease($record)) {
            return redirect()
                ->route('postmaster.messages.show', $record)
                ->with('postmasterError', $this->releaseBlockedReason($record));
        }

        // Guard against a double-click firing two sends before the first has
        // flipped the row out of "sandboxed".
        $throttleSeconds = (int) config('postmaster.dashboard.resend_throttle_seconds', 60);
        $cacheKey = "postmaster.release.{$record->getKey()}";

        if ($throttleSeconds > 0 && Cache::has($cacheKey)) {
            return redirect()
                ->route('postmaster.messages.show', $record)
                ->with('postmasterError', 'Already releasing this message. Give it a moment.');
        }

        if ($throttleSeconds > 0) {
            Cache::put($cacheKey, true, now()->addSeconds($throttleSeconds));
        }

        Postmaster::release($record);

        return redirect()
            ->route('postmaster.messages.show', $record)
            ->with('postmasterFlash', "Released — {$record->to_address} was sent for real.");
    }

    /**
     * The reason a release was refused, for the flash message.
     */
    protected function releaseBlockedReason(EmailMessage $record): string
    {
        if (! $record->isSandboxed()) {
            return "Can't release — this message isn't sandboxed (it may already have been released).";
        }

        if (! $record->html_body && ! $record->text_body) {
            return "Can't release — no stored content to send. Content storage was off when it was sandboxed.";
        }

        return "Can't release — {$record->to_address} is suppressed. Unsuppress the address first.";
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
