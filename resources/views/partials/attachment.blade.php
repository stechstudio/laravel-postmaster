{{-- One attachment, as a compact card rather than a full-width row. Mail
     clients show attachments as chips for a reason: a row per file gives one
     PDF the same width as the message itself, so a two-attachment email reads
     as though the files were the point of it.

     The whole chip is the download link when the bytes are still held. When
     they're gone it isn't a link at all, and carries the reason instead of a
     dead target. --}}
@php
    $isImage = str_starts_with((string) $attachment->mime_type, 'image/');
@endphp

@if ($attachment->isAvailable())
    <a class="pm-att-chip" href="{{ route('postmaster.messages.attachment', [$message, $attachment]) }}"
       title="Download {{ $attachment->filename }}">
        <span class="pm-att-icon">@include('postmaster::partials.att-icon', ['image' => $isImage])</span>
        <span class="pm-att-main">
            <span class="pm-att-name">{{ $attachment->filename }}</span>
            <span class="pm-att-sub">{{ $attachment->humanSize() }}</span>
        </span>
    </a>
@else
    <div class="pm-att-chip is-gone">
        <span class="pm-att-icon">@include('postmaster::partials.att-icon', ['image' => $isImage])</span>
        <span class="pm-att-main">
            <span class="pm-att-name">{{ $attachment->filename }}</span>
            <span class="pm-att-sub">{{ $attachment->humanSize() }} · {{ str_replace('_', ' ', $attachment->status->value) }}</span>
        </span>
    </div>
@endif
