@extends('postmaster::layout')

@section('title', 'Activity')

@section('content')
    @unless ($enabled)
        <div class="pm-card">
            <div class="pm-dim">
                Timeline recording is off, so this stream stays empty. Enable
                <span class="pm-mono">POSTMASTER_RECORD_EVENTS</span> to record events as they arrive.
            </div>
        </div>
    @endunless

    <div class="pm-card" style="padding: 0;">
        @include('postmaster::partials.activity-table', [
            'events' => $events,
            'lastId' => $lastId,
            'limit'  => 200,
        ])
    </div>
@endsection
