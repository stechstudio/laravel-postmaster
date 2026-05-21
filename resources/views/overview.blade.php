@extends('postmaster::layout')

@section('title', 'Overview')

@section('content')
    <div class="pm-grid pm-grid--stats">
        @php
            $tiles = [
                ['Total messages', $total],
                ['Delivered', $byStatus->get('delivered', 0)],
                ['Bounced', $byStatus->get('bounced', 0)],
                ['Complained', $byStatus->get('complained', 0)],
                ['Suppressed addresses', $suppressed],
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
        <h2 class="pm-section-title">Messages — last 14 days</h2>
        @php
            $max = max(1, collect($chart)->max('count'));
            $slot = 48; $barW = 26; $plotH = 100;
            $width = count($chart) * $slot;
        @endphp
        <svg class="pm-chart" viewBox="0 0 {{ $width }} 130" preserveAspectRatio="none">
            @foreach ($chart as $i => $bar)
                @php
                    $h = round($bar['count'] / $max * $plotH, 1);
                    $x = $i * $slot + ($slot - $barW) / 2;
                @endphp
                <rect x="{{ $x }}" y="{{ $plotH - $h }}" width="{{ $barW }}" height="{{ max($h, 1) }}" rx="3">
                    <title>{{ $bar['date']->format('M j') }} — {{ $bar['count'] }}</title>
                </rect>
                <text class="pm-chart-axis" x="{{ $x + $barW / 2 }}" y="120" text-anchor="middle">{{ $bar['date']->format('j') }}</text>
            @endforeach
        </svg>
    </div>
@endsection
