<div class="pm-pager">
    <span class="pm-dim">{{ number_format($paginator->total()) }} {{ $label }}</span>
    <span class="pm-tabs">
        @if ($paginator->previousPageUrl())
            <a class="pm-btn pm-btn--ghost pm-btn--sm" href="{{ $paginator->previousPageUrl() }}">← Prev</a>
        @endif
        @if ($paginator->lastPage() > 1)
            <span class="pm-page-of">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @endif
        @if ($paginator->nextPageUrl())
            <a class="pm-btn pm-btn--ghost pm-btn--sm" href="{{ $paginator->nextPageUrl() }}">Next →</a>
        @endif
    </span>
</div>
