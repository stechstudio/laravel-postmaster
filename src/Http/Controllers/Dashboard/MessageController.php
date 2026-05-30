<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailAddress;

/**
 * The inbox: a filterable, cross-tenant list of recorded messages, and the
 * per-message detail view with its delivery timeline and stored content.
 */
class MessageController extends Controller
{
    public function index( Request $request )
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
            $query->where($this->tenantColumn(), $tenant);
        }

        if ($tag = $request->query('tag')) {
            $query->taggedWith($tag);
        }

        $this->applyContains($query, 'to_address', $request->query('to'));
        $this->applyContains($query, 'subject', $request->query('subject'));
        $this->applyDateRange($query, 'created_at', $request->query('from'), $request->query('to'));

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

    public function show( $message )
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
            'tenants'        => $this->tenantLabels([$record->{$this->tenantColumn()}]),
            'tenantTerm'     => $this->tenantTerm(),
            'recipientLabel' => $this->labelForRecipientOnRecord($record),
            'canResend'      => $this->canResend($record),
            // Remote images are blocked by the preview CSP. The viewer can
            // opt in per view with ?images=1; the bar is offered only when
            // the message actually has a remote image to unblock.
            'showImages'      => request()->boolean('images'),
            'hasRemoteImages' => $this->hasRemoteImages($record->html_body),
        ]);
    }

    /**
     * Whether the Resend button should render on this row. False when:
     *   - there's no stored content to replay, or
     *   - the recipient is currently suppressed locally (the dashboard
     *     wants the operator to clear the suppression intentionally before
     *     re-sending). Operator can still resend via the EmailMessage::resend()
     *     API from their own code — this is just the dashboard's UX choice.
     *
     * @return bool
     */
    protected function canResend( $record )
    {
        if (! $record->html_body && ! $record->text_body) {
            return false;
        }

        if (! $record->to_address) {
            return false;
        }

        $addressClass = config('postmaster.persistence.address_model', EmailAddress::class);

        $address = (new $addressClass)->newQuery()
            ->where('address', $addressClass::normalize($record->to_address))
            ->first();

        return ! $address || ! $address->isSuppressed();
    }

    /**
     * Resend a previously recorded email — typically after a bounce, once
     * the recipient has corrected their address. The replay carries over
     * subject, sender, recipients, bodies, and the tracking context, plus
     * a "resent" tag of its own. Requires stored content; attachments are
     * not restored (we never keep their bytes).
     *
     * @param Request    $request
     * @param int|string $message
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function resend( Request $request, $message )
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
     * Every message recorded against a single recipient-model — the "person
     * view." The morph type is taken straight from the URL (any morph map
     * the app registered applies), so existing morph aliases work without
     * extra wiring.
     *
     * @param string     $type
     * @param int|string $id
     *
     * @return \Illuminate\Http\Response
     */
    public function forRecipient( $type, $id )
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
            'tenants'    => $this->tenantLabels($messages->pluck($this->tenantColumn())->all()),
            'tenantTerm' => $this->tenantTerm(),
        ]);
    }

    /**
     * Try to load the recipient model behind a (type, id) pair so we can
     * label it. Returns null when the type does not resolve, the row no
     * longer exists, or persistence is using a different connection — the
     * person view still works in any of those cases, just without a name.
     *
     * @param string     $type
     * @param int|string $id
     *
     * @return Model|null
     */
    protected function loadRecipient( $type, $id )
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
     *
     * @param \STS\Postmaster\Models\EmailMessage $record
     *
     * @return string|null
     */
    protected function labelForRecipientOnRecord( $record )
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
     *
     * @param mixed $html
     *
     * @return bool
     */
    protected function hasRemoteImages( $html )
    {
        return is_string($html)
            && preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']?\s*(?:https?:)?\/\//i', $html) === 1;
    }

    /**
     * Distinct provider names present in the messages table. Providers are
     * stored under their display name ("SendGrid"), so the filter options
     * must come from the data — not the lower-case config keys.
     *
     * @return array
     */
    protected function providersInUse()
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
     * @return array
     */
    protected function tagsInUse()
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
