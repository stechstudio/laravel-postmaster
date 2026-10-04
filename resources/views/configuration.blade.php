@extends('postmaster::layout')

@section('title', 'Configuration')

@php
    $sections = $report->sections();
@endphp

@section('content')
    <p class="pm-dim pm-config-intro">
        Read-only. Change these in <span class="pm-mono">.env</span> or
        <span class="pm-mono">config/postmaster.php</span>, then run
        <span class="pm-mono">php artisan config:clear</span>.
    </p>

    <div class="pm-stats pm-stats--words">
        @foreach ($report->summary() as [$label, $value, $tone, $caption])
            <div class="pm-stat">
                <div class="pm-stat-label">{{ $label }}</div>
                <div class="pm-stat-value pm-tone--{{ $tone }}">{{ $value }}</div>
                <div class="pm-stat-caption">{{ $caption }}</div>
            </div>
        @endforeach
    </div>

    @if ($checks = $report->checks())
        <div class="pm-card pm-card--flush">
            <div class="pm-card-head">
                <h2 class="pm-section-title">Needs attention</h2>
            </div>
            @foreach ($checks as [$tone, $title, $body])
                <div class="pm-check pm-check--{{ $tone }}">
                    <div class="pm-check-title">{{ $title }}</div>
                    <div class="pm-check-body">{{ $body }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @include('postmaster::partials.mailers', ['mailers' => $report->mailers(), 'olderSends' => $report->hasSendsWithoutMailer()])

    <div class="pm-grid pm-grid--pair">
        @foreach (['Sending', 'Webhooks', 'Recording', 'Retention'] as $title)
            @include('postmaster::partials.settings-card', [
                'title' => $title,
                'description' => $sections[$title][0],
                'rows' => $sections[$title][1],
                'lastWebhook' => $title === 'Webhooks' ? $lastWebhook : false,
            ])
        @endforeach
    </div>
@endsection
