{{-- A status pill. The tone map lives in PHP so the live activity feed can
     colour its rows the same way — see StatusTone. --}}
@use('STS\Postmaster\Support\StatusTone')
<span class="pm-badge pm-badge--{{ StatusTone::for($status ?? null) }}">{{ $status ?? '—' }}</span>
