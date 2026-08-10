@extends('postmaster::layout')

@section('title', 'Message')

@section('content')
    @php
        // Stored email HTML is rendered inert: the iframe sandbox blocks
        // scripts, and this CSP blocks remote subresources. Remote images are
        // off by default — only data: images load — so opening a message
        // doesn't leak the viewer's IP. ?images=1 relaxes img-src for this
        // view only; scripts, forms, fetch and fonts stay blocked either way.
        $imgSrc = $showImages ? 'data: https: http:' : 'data:';
        $previewCsp = '<meta http-equiv="Content-Security-Policy" '
            ."content=\"default-src 'none'; style-src 'unsafe-inline'; img-src {$imgSrc};\">";

        // The smallest set of defaults a mail client would apply, and no more.
        // An email that brings its own layout overrides all of it; one that
        // doesn't would otherwise render against the browser's 8px body margin
        // and read as raw output rather than as a message.
        //
        // Padding rather than margin on <body> is deliberate: the body's own
        // background still paints under it, so a full-bleed design stays
        // full-bleed instead of gaining white gutters.
        $previewReset = '<style>'
            .'body{margin:0;padding:22px;}'
            // Wide images and fixed-width tables would otherwise force a
            // horizontal scrollbar across the whole preview.
            .'img{max-width:100%;height:auto;}'
            .'table{max-width:100%;}'
            .'</style>';

        $tenantColumn = config('postmaster.persistence.tenant_column', 'tenant_id');
        $recipients = $message->recipients ?: [];
    @endphp

    <div class="pm-detail-bar">
        <a href="{{ route('postmaster.messages') }}" class="pm-btn pm-btn--ghost">← Back to messages</a>
        <div class="pm-detail-actions">
            @if ($canRelease)
                <form method="POST" action="{{ route('postmaster.messages.release', $message) }}"
                      onsubmit="return confirm('Release this sandboxed email and send it for real to {{ $message->to_address }}? This can\'t be undone.')">
                    @csrf
                    <button type="submit" class="pm-btn"
                            @if ($message->missingAttachmentCount() > 0) title="{{ $message->missingAttachmentCount() }} attachment(s) are no longer stored and won't be included." @endif>
                        Release
                    </button>
                </form>
            @endif
            @if ($canResend)
                <form method="POST" action="{{ route('postmaster.messages.resend', $message) }}"
                      onsubmit="return confirm('Resend this email to {{ $message->to_address }}?')">
                    @csrf
                    <button type="submit" class="pm-btn"
                            @if ($message->missingAttachmentCount() > 0) title="{{ $message->missingAttachmentCount() }} attachment(s) are no longer stored and won't be included." @endif>
                        Resend
                    </button>
                </form>
            @endif
            <form method="POST" action="{{ route('postmaster.messages.destroy', $message) }}"
                  onsubmit="return confirm('Delete this message from your stored history?\n\nThis only removes Postmaster\'s record of the email. It does NOT recall, unsend, or delete the message if it was already sent — a delivered email stays in the recipient\'s inbox.\n\nThis cannot be undone.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="pm-btn pm-btn--danger">Delete</button>
            </form>
        </div>
    </div>

    <div class="pm-detail-grid">
        {{-- Header, attachments and body are one message, not three findings
             about it — so they're bands inside a single surface rather than a
             stack of separate cards, each drawing another edge across
             something the reader is trying to take in as a whole. --}}
        <div class="pm-card pm-message">
            <div class="pm-message-head">
                <h1 class="pm-email-subject">{{ $message->subject ?: '(no subject)' }}</h1>
                <dl class="pm-meta">
                    @if ($message->from_address)
                        <dt>From</dt><dd>{{ $message->from_address }}</dd>
                    @endif
                    <dt>{{ ucfirst($message->recipient_role ?? 'to') }}</dt>
                    <dd>{{ $message->to_address ?? '—' }}</dd>
                    @foreach (['cc' => 'Cc', 'bcc' => 'Bcc'] as $key => $label)
                        @if (! empty($recipients[$key]))
                            <dt>{{ $label }}</dt>
                            <dd>{{ collect($recipients[$key])->pluck('address')->implode(', ') }}</dd>
                        @endif
                    @endforeach
                    <dt>Date</dt><dd>@include('postmaster::partials.datetime', ['when' => $message->sent_at, 'style' => 'long'])</dd>
                </dl>
            </div>

            {{-- Above the body, not below it. What went out attached is a fact
                 about the message — the same class of thing as From and Date —
                 and this is an audit view, not an inbox: nobody opens it to
                 read the email. Gmail can afford to put attachments under the
                 body because the body flows in the page and you scroll past it
                 to get there; ours is a fixed-height iframe that takes the
                 scroll gesture first, so down there they'd go unnoticed.

                 Real attachments only. Embedded images belong to the body, not
                 to this list — nobody sees them paperclipped on. --}}
            @php
                $files = $message->fileAttachments();
                $legacy = $message->legacyAttachmentNames();
                $fileCount = $files->count() + count($legacy);
                $summary = $fileCount.' '.\Illuminate\Support\Str::plural('file', $fileCount)
                    .($files->isNotEmpty()
                        ? ' · '.\STS\Postmaster\Models\EmailAttachment::humanBytes($files->sum('size'))
                        : '');
            @endphp

            @if ($files->isNotEmpty() || $legacy)
                <div class="pm-attachments">
                    <div class="pm-att-head">Attachments <span class="pm-dim">· {{ $summary }}</span></div>
                    <div class="pm-att-chips">
                        @foreach ($files as $attachment)
                            @include('postmaster::partials.attachment', ['message' => $message, 'attachment' => $attachment])
                        @endforeach

                        {{-- Recorded before the attachments table existed: we
                             have the name and nothing else. --}}
                        @foreach ($legacy as $name)
                            <div class="pm-att-chip is-gone">
                                <span class="pm-att-icon">@include('postmaster::partials.att-icon', ['image' => false])</span>
                                <span class="pm-att-main">
                                    <span class="pm-att-name">{{ $name }}</span>
                                    <span class="pm-att-sub">not stored</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($message->html_body)
                @if ($hasRemoteImages && ! $showImages)
                    <div class="pm-imgbar">
                        <span>Remote images aren't shown in this preview.</span>
                        <a href="{{ route('postmaster.messages.show', ['message' => $message, 'images' => 1]) }}"
                           class="pm-btn pm-btn--sm">Show images</a>
                    </div>
                @endif
                {{-- Embedded images are substituted in as data URIs by
                     previewBody(). When one can't be — never captured, or
                     since pruned or evicted — say so, rather than leaving a
                     broken icon that reads as a broken email. --}}
                @if ($message->hasUnresolvedInlineImages())
                    <div class="pm-imgbar">
                        <span>Some embedded images are no longer stored, and can't be shown.</span>
                    </div>
                @endif
                <div class="pm-message-body">
                    {{-- allow-same-origin lets the page opposite measure this
                         document so the frame can grow to its content. It is
                         safe only because allow-scripts is absent: nothing can
                         execute in here, so there is no code to make use of the
                         shared origin. Never add allow-scripts to this
                         attribute — the two together let framed content strip
                         its own sandbox and take this page's origin with it. --}}
                    <iframe class="pm-frame" sandbox="allow-same-origin"
                            srcdoc="{{ $previewCsp.$previewReset.$message->previewBody() }}" title="Message body"></iframe>
                </div>
            @elseif ($message->text_body)
                <div class="pm-message-body">
                    <div class="pm-pre">{{ $message->text_body }}</div>
                </div>
            @else
                <div class="pm-message-body">
                    <div class="pm-empty">
                        Message content was not stored.<br>
                        Enable <span class="pm-mono">POSTMASTER_STORE_CONTENT</span> to capture it.
                    </div>
                </div>
            @endif

        </div>

        <div>
            <div class="pm-card">
                <h2 class="pm-section-title">Details</h2>
                <dl class="pm-meta">
                    <dt>Status</dt>
                    <dd>@include('postmaster::partials.badge', ['status' => $message->status])</dd>
                    @if ($message->bounce_type)
                        <dt>Bounce</dt><dd>{{ $message->bounce_type }}</dd>
                    @endif
                    <dt>Provider</dt><dd>{{ $message->provider ?? '—' }}</dd>
                    <dt>Message ID</dt><dd class="pm-mono pm-truncate">{{ $message->provider_message_id ?? '—' }}</dd>
                    @if ($message->{$tenantColumn})
                        <dt>{{ $tenantTerm }}</dt>
                        <dd>{{ $tenants[$message->{$tenantColumn}] ?? $message->{$tenantColumn} }}</dd>
                    @endif
                    @if ($recipientLabel)
                        <dt>Recipient</dt>
                        <dd>
                            <a class="pm-link" href="{{ route('postmaster.recipient', ['type' => $message->recipient_type, 'id' => $message->recipient_id]) }}">{{ $recipientLabel }}</a>
                        </dd>
                    @endif
                    @if ($message->related_type)
                        <dt>Related</dt><dd class="pm-mono">{{ class_basename($message->related_type) }} #{{ $message->related_id }}</dd>
                    @endif
                    @if (! empty($message->tags))
                        <dt>Tags</dt>
                        <dd class="pm-tags">
                            @foreach ($message->tags as $tag)
                                <span class="pm-badge pm-badge--muted">{{ $tag }}</span>
                            @endforeach
                        </dd>
                    @endif
                    <dt>Last event</dt><dd>@include('postmaster::partials.datetime', ['when' => $message->last_event_at])</dd>
                </dl>
            </div>

            @if ($siblings->isNotEmpty())
                <div class="pm-card">
                    <h2 class="pm-section-title">Also sent to</h2>
                    <div class="pm-siblings">
                        @foreach ($siblings as $sibling)
                            <a class="pm-siblings-row" href="{{ route('postmaster.messages.show', $sibling) }}">
                                <span class="pm-role-tag pm-role-tag--{{ $sibling->recipient_role }}">{{ $sibling->recipient_role }}</span>
                                <span class="pm-truncate">{{ $sibling->to_address }}</span>
                                @include('postmaster::partials.badge', ['status' => $sibling->status])
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($chain->isNotEmpty())
                <div class="pm-card">
                    <h2 class="pm-section-title">Resend chain</h2>
                    <div class="pm-siblings">
                        @foreach ($chain as $link)
                            @php $isCurrent = $link->getKey() === $message->getKey(); @endphp
                            <a class="pm-siblings-row{{ $isCurrent ? ' pm-siblings-row--current' : '' }}"
                               href="{{ route('postmaster.messages.show', $link) }}">
                                <span class="pm-truncate pm-dim">@include('postmaster::partials.datetime', ['when' => $link->sent_at])</span>
                                @include('postmaster::partials.badge', ['status' => $link->status])
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="pm-card">
                <h2 class="pm-section-title">Timeline</h2>
                <div class="pm-timeline">
                    @forelse ($activity as $event)
                        <div class="pm-timeline-item">
                            <div style="flex: 1;">
                                @include('postmaster::partials.badge', ['status' => $event->status])
                                @if ($event->reason)
                                    <span class="pm-dim">— {{ $event->reason }}</span>
                                @endif
                                @if ($event->url)
                                    <div class="pm-timeline-url pm-dim pm-truncate">
                                        → <a class="pm-link" href="{{ $event->url }}" target="_blank" rel="noopener">{{ $event->url }}</a>
                                    </div>
                                @endif
                            </div>
                            <div class="pm-timeline-when">@include('postmaster::partials.datetime', ['when' => $event->occurred_at])</div>
                        </div>
                    @empty
                        <div class="pm-dim">No timeline events recorded.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <script>
        // Grow the preview to its content. A fixed height leaves a short email
        // sitting above a tall run of white, and makes a long one scroll inside
        // a nested scrollbar that the page's own scroll gesture doesn't reach.
        //
        // Reading the framed document is what allow-same-origin on the sandbox
        // buys. If measuring ever fails the CSS height stands, so the preview
        // degrades to what it was rather than collapsing.
        (function () {
            var frame = document.querySelector('.pm-frame');
            if (! frame) return;

            function fit() {
                try {
                    var doc = frame.contentDocument;
                    if (! doc || ! doc.documentElement) return;
                    // The document can never measure shorter than the frame it
                    // sits in, so a frame that is currently too tall reports
                    // its own height back. Collapsing it first lets the content
                    // report its true height; the reassignment below happens in
                    // the same frame, so nothing is painted at zero.
                    frame.style.height = '0px';
                    frame.style.height = Math.max(doc.documentElement.scrollHeight, 80) + 'px';
                } catch (e) {
                    // Blocked from reading it — leave the CSS height alone.
                }
            }

            frame.addEventListener('load', function () {
                fit();
                // Images settle after load and change the height with them.
                try {
                    Array.prototype.forEach.call(frame.contentDocument.images, function (img) {
                        if (! img.complete) img.addEventListener('load', fit);
                    });
                } catch (e) { /* nothing to attach to */ }
            });

            fit();

            // A narrower frame reflows the email taller, so remeasure on
            // resize — of the container, not the frame, which this changes.
            if (window.ResizeObserver && frame.parentElement) {
                new ResizeObserver(fit).observe(frame.parentElement);
            }
        })();
    </script>
@endsection
