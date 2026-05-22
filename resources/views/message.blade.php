@extends('postmaster::layout')

{{-- Escaped here: the layout yields the title into <title>/<h1> unescaped,
     and an email subject is attacker-influenced content. --}}
@section('title', e($message->subject ?: '(no subject)'))

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
    @endphp

    <div>
        <a href="{{ route('postmaster.messages') }}" class="pm-btn pm-btn--ghost">← Back to messages</a>
    </div>

    <div class="pm-detail-grid">
        <div>
            @if ($message->html_body)
                @if ($hasRemoteImages && ! $showImages)
                    <div class="pm-imgbar">
                        <span>Remote images aren't shown in this preview.</span>
                        <a href="{{ route('postmaster.messages.show', ['message' => $message, 'images' => 1]) }}"
                           class="pm-btn pm-btn--sm">Show images</a>
                    </div>
                @endif
                <iframe class="pm-frame" sandbox srcdoc="{{ $previewCsp.$message->html_body }}" title="Message body"></iframe>
            @elseif ($message->text_body)
                <div class="pm-pre">{{ $message->text_body }}</div>
            @else
                <div class="pm-card">
                    <div class="pm-empty">
                        Message content was not stored.<br>
                        Enable <span class="pm-mono">POSTMASTER_STORE_CONTENT</span> to capture it.
                    </div>
                </div>
            @endif

            @if (! empty($message->attachments))
                <div style="margin-top: 14px;">
                    <div class="pm-stat-label">Attachments</div>
                    <ul class="pm-mono" style="margin: 6px 0 0; padding-left: 18px;">
                        @foreach ($message->attachments as $name)
                            <li>{{ $name }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div>
            <div class="pm-card">
                <h2 class="pm-section-title">Details</h2>
                @php
                    $tenantColumn = config('postmaster.persistence.tenant_column', 'tenant_id');
                    $recipients = $message->recipients ?: [];
                @endphp
                <dl class="pm-meta">
                    <dt>Status</dt>
                    <dd>@include('postmaster::partials.badge', ['status' => $message->status])</dd>
                    @if ($message->bounce_type)
                        <dt>Bounce</dt><dd>{{ $message->bounce_type }}</dd>
                    @endif
                    <dt>To</dt><dd class="pm-mono">{{ $message->recipient ?? '—' }}</dd>
                    @if ($message->from_address)
                        <dt>From</dt><dd class="pm-mono">{{ $message->from_address }}</dd>
                    @endif
                    @foreach (['cc' => 'CC', 'bcc' => 'BCC'] as $key => $label)
                        @if (! empty($recipients[$key]))
                            <dt>{{ $label }}</dt>
                            <dd class="pm-mono">{{ collect($recipients[$key])->pluck('address')->implode(', ') }}</dd>
                        @endif
                    @endforeach
                    <dt>Provider</dt><dd>{{ $message->provider ?? '—' }}</dd>
                    <dt>Message ID</dt><dd class="pm-mono pm-truncate">{{ $message->message_id ?? '—' }}</dd>
                    @if ($message->{$tenantColumn})
                        <dt>{{ $tenantTerm }}</dt>
                        <dd>{{ $tenants[$message->{$tenantColumn}] ?? $message->{$tenantColumn} }}</dd>
                    @endif
                    @if ($message->related_type)
                        <dt>Related</dt><dd class="pm-mono">{{ class_basename($message->related_type) }} #{{ $message->related_id }}</dd>
                    @endif
                    <dt>Sent</dt><dd>{{ $message->sent_at?->format('M j, g:ia') ?? '—' }}</dd>
                    <dt>Last event</dt><dd>{{ $message->last_event_at?->format('M j, g:ia') ?? '—' }}</dd>
                </dl>
            </div>

            <div class="pm-card">
                <h2 class="pm-section-title">Timeline</h2>
                <div class="pm-timeline">
                    @forelse ($events as $event)
                        <div class="pm-timeline-item">
                            <div style="flex: 1;">
                                @include('postmaster::partials.badge', ['status' => $event->status])
                                @if ($event->reason)
                                    <span class="pm-dim">— {{ $event->reason }}</span>
                                @endif
                            </div>
                            <div class="pm-timeline-when">{{ $event->occurred_at?->format('M j, g:ia') }}</div>
                        </div>
                    @empty
                        <div class="pm-dim">No timeline events recorded.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
