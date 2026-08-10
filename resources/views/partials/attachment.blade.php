{{-- One attachment row. When the bytes are still held the whole row is the
     download link — the glyph stays visible rather than appearing on hover,
     which touch and keyboard users never get. When they're gone the row isn't
     a link at all, and carries the reason instead of a dead target. --}}
@php
    $isImage = str_starts_with((string) $attachment->mime_type, 'image/');
@endphp

@if ($attachment->isAvailable())
    <a class="pm-att-row" href="{{ route('postmaster.messages.attachment', [$message, $attachment]) }}">
        <span class="pm-att-icon">@include('postmaster::partials.att-icon', ['image' => $isImage])</span>
        <span class="pm-att-main">
            <span class="pm-att-name">{{ $attachment->filename }}</span>
            <span class="pm-att-sub">{{ $attachment->humanSize() }}</span>
        </span>
        <span class="pm-att-get" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <path d="M7 10l5 5 5-5"/>
                <path d="M12 15V3"/>
            </svg>
        </span>
    </a>
@else
    <div class="pm-att-row is-gone">
        <span class="pm-att-icon">@include('postmaster::partials.att-icon', ['image' => $isImage])</span>
        <span class="pm-att-main">
            <span class="pm-att-name">{{ $attachment->filename }}</span>
            <span class="pm-att-sub">
                {{ $attachment->humanSize() }}
                · <span class="pm-badge pm-badge--muted">{{ str_replace('_', ' ', $attachment->status->value) }}</span>
            </span>
        </span>
    </div>
@endif
