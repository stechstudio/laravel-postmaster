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
        <div class="pm-toolbar" style="margin-bottom: 16px;">
            <h2 class="pm-section-title" style="margin: 0;">Messages sent</h2>
            <div class="pm-pills">
                @foreach (['7' => '7 days', '30' => '30 days', '90' => '90 days', '365' => '1 year'] as $value => $label)
                    <a href="{{ route('postmaster.overview', ['days' => $value]) }}"
                       class="{{ (int) $value === $days ? 'is-active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>

        @php
            $max = max(1, collect($chart)->max('count'));
            $slot = 48; $barW = 26; $plotH = 100;
            $width = max(count($chart), 1) * $slot;
        @endphp
        <div class="pm-chart">
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
@endsection
