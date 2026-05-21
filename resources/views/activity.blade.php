@extends('postmaster::layout')

@section('title', 'Activity')

@section('content')
    @unless ($enabled)
        <div class="pm-card">
            <div class="pm-dim">
                Timeline recording is off, so there is nothing here yet. Enable
                <span class="pm-mono">POSTMASTER_RECORD_EVENTS</span> to record events as they arrive.
            </div>
        </div>
    @endunless

    <div class="pm-card">
        <form method="GET" action="{{ route('postmaster.activity') }}" class="pm-filters" x-data>
            <div class="pm-field">
                <label>Status</label>
                <select name="status" class="pm-select" onchange="this.form.requestSubmit()">
                    <option value="">Any</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pm-field">
                <label>Recipient</label>
                <input type="text" name="recipient" class="pm-input" placeholder="contains…"
                       value="{{ $filters['recipient'] ?? '' }}"
                       x-on:input.debounce.400ms="($el.value.length >= 3 || $el.value.length === 0) && $el.form.requestSubmit()">
            </div>
            <div class="pm-field">
                <label>From</label>
                <input type="date" name="from" class="pm-input" value="{{ $filters['from'] ?? '' }}"
                       onchange="this.form.requestSubmit()">
            </div>
            <div class="pm-field">
                <label>To</label>
                <input type="date" name="to" class="pm-input" value="{{ $filters['to'] ?? '' }}"
                       onchange="this.form.requestSubmit()">
            </div>
            <a href="{{ route('postmaster.activity') }}" class="pm-btn pm-btn--ghost">Clear</a>
        </form>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $events, 'label' => 'events'])

    <div class="pm-card" style="padding: 0;">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Recipient</th>
                    <th>Status</th>
                    <th>Provider</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr class="pm-row-link" onclick="location.href='{{ route('postmaster.messages.show', $event->email_message_id) }}'">
                        <td class="pm-dim">{{ $event->occurred_at?->format('M j, g:ia') ?? '—' }}</td>
                        <td class="pm-mono">{{ $event->emailMessage?->recipient ?? '—' }}</td>
                        <td>@include('postmaster::partials.badge', ['status' => $event->status])</td>
                        <td class="pm-dim">{{ $event->provider ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="pm-empty">No activity matches these filters.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $events, 'label' => 'events'])
@endsection
