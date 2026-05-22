@extends('postmaster::layout')

@section('title', 'Overview')

@section('content')
    {{-- Timeframe applies to the stat cards and the chart below. --}}
    <div class="pm-pills">
        @foreach ($ranges as $value => $label)
            <a href="{{ route('postmaster.overview', ['days' => $value]) }}"
               class="{{ $value === $days ? 'is-active' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="pm-grid pm-grid--stats">
        @php
            $tiles = [
                ['Messages sent', $total],
                ['Delivered', $byStatus->get('delivered', 0)],
                ['Bounced', $byStatus->get('bounced', 0)],
                ['Complained', $byStatus->get('complained', 0)],
                ['Newly suppressed', $suppressed],
            ];
        @endphp
        @foreach ($tiles as [$label, $value])
            <div class="pm-card pm-stat">
                <div class="pm-stat-label">{{ $label }}</div>
                <div class="pm-stat-value">{{ number_format($value) }}</div>
            </div>
        @endforeach
    </div>

    <div class="pm-card">
        <h2 class="pm-section-title">Messages sent</h2>
        @php
            $max = max(1, collect($chart)->max('count'));
            $slot = 48; $barW = 26; $plotH = 100;
            $width = max(count($chart), 1) * $slot;
        @endphp
        {{-- Dense charts thin their x-axis labels on narrow screens. --}}
        <div class="pm-chart {{ count($chart) > 8 ? 'pm-chart--dense' : '' }}">
            <svg class="pm-chart-bars" viewBox="0 0 {{ $width }} {{ $plotH }}" preserveAspectRatio="none">
                @foreach ($chart as $i => $bar)
                    @php
                        $h = max(round($bar['count'] / $max * $plotH, 1), 1);
                        $x = $i * $slot + ($slot - $barW) / 2;
                    @endphp
                    <rect x="{{ $x }}" y="{{ $plotH - $h }}" width="{{ $barW }}" height="{{ $h }}" rx="3">
                        <title>{{ $bar['date']->format('M j') }} — {{ $bar['count'] }}</title>
                    </rect>
                @endforeach
            </svg>
            <div class="pm-chart-labels">
                @foreach ($chart as $bar)
                    <span>{{ $bar['interval'] === 1 ? $bar['date']->format('j') : $bar['date']->format('M j') }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="pm-grid pm-grid--halves">
        <div class="pm-card" style="padding: 0;">
            <div class="pm-card-head">
                <h2 class="pm-section-title" style="margin: 0;">Recent messages</h2>
                <a href="{{ route('postmaster.messages') }}" class="pm-link">View all →</a>
            </div>
            <div class="pm-feed">
                @forelse ($recentMessages as $message)
                    <div class="pm-feed-row pm-row-link" onclick="location.href='{{ route('postmaster.messages.show', $message) }}'">
                        <div class="pm-feed-line">
                            <span class="pm-feed-primary">{{ $message->subject ?: '(no subject)' }}</span>
                            @include('postmaster::partials.badge', ['status' => $message->status])
                        </div>
                        <div class="pm-feed-line">
                            <span class="pm-feed-secondary">{{ $message->recipient ?? '—' }}</span>
                            <span class="pm-feed-meta">{{ $message->sent_at?->format('M j, g:ia') ?? '—' }}</span>
                        </div>
                    </div>
                @empty
                    <div class="pm-empty">No messages yet.</div>
                @endforelse
            </div>
        </div>

        <div class="pm-card" style="padding: 0;">
            <div class="pm-card-head">
                <h2 class="pm-section-title" style="margin: 0;">Recent activity</h2>
                <a href="{{ route('postmaster.activity') }}" class="pm-link">View all →</a>
            </div>
            @include('postmaster::partials.activity-feed', [
                'events' => $recentEvents,
                'lastId' => $recentLastId,
                'limit'  => 8,
            ])
        </div>
    </div>
@endsection
