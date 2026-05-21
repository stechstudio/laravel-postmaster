<div class="pm-pager">
    <span class="pm-dim">{{ number_format($paginator->total()) }} {{ $label }}</span>
    <span class="pm-tabs">
        @if ($paginator->previousPageUrl())
            <a class="pm-btn pm-btn--ghost pm-btn--sm" href="{{ $paginator->previousPageUrl() }}">← Prev</a>
        @endif
        @if ($paginator->nextPageUrl())
            <a class="pm-btn pm-btn--ghost pm-btn--sm" href="{{ $paginator->nextPageUrl() }}">Next →</a>
        @endif
    </span>
</div>
