@extends('postmaster::layout')

@section('title', 'Messages')

@section('content')
    @php $tenantColumn = config('postmaster.persistence.tenant_column', 'tenant_id'); @endphp

    <div class="pm-card">
        <form method="GET" action="{{ route('postmaster.messages') }}" class="pm-filters">
            <div class="pm-field">
                <label>Status</label>
                <select name="status" class="pm-select">
                    <option value="">Any</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pm-field">
                <label>Provider</label>
                <select name="provider" class="pm-select">
                    <option value="">Any</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider }}" @selected(($filters['provider'] ?? '') === $provider)>{{ ucfirst($provider) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pm-field">
                <label>Recipient</label>
                <input type="text" name="recipient" class="pm-input" placeholder="starts with…" value="{{ $filters['recipient'] ?? '' }}">
            </div>
            <div class="pm-field">
                <label>Tenant</label>
                <input type="text" name="tenant" class="pm-input" value="{{ $filters['tenant'] ?? '' }}">
            </div>
            <div class="pm-field">
                <label>From</label>
                <input type="date" name="from" class="pm-input" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="pm-field">
                <label>To</label>
                <input type="date" name="to" class="pm-input" value="{{ $filters['to'] ?? '' }}">
            </div>
            <button type="submit" class="pm-btn">Filter</button>
            <a href="{{ route('postmaster.messages') }}" class="pm-btn pm-btn--ghost">Clear</a>
        </form>
    </div>

    <div class="pm-card" style="padding: 0;">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Provider</th>
                    <th>Tenant</th>
                    <th>Sent</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($messages as $message)
                    <tr class="pm-row-link" onclick="location.href='{{ route('postmaster.messages.show', $message) }}'">
                        <td class="pm-mono">{{ $message->recipient ?? '—' }}</td>
                        <td class="pm-truncate">{{ $message->subject ?? '—' }}</td>
                        <td>@include('postmaster::partials.badge', ['status' => $message->status])</td>
                        <td class="pm-dim">{{ $message->provider ?? '—' }}</td>
                        <td class="pm-dim">{{ $message->{$tenantColumn} ?? '—' }}</td>
                        <td class="pm-dim">{{ optional($message->sent_at)->format('M j, H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="pm-empty">No messages match these filters.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pm-pager">
        <span class="pm-dim">{{ number_format($messages->total()) }} messages</span>
        <span class="pm-tabs">
            @if ($messages->previousPageUrl())
                <a class="pm-btn pm-btn--ghost pm-btn--sm" href="{{ $messages->previousPageUrl() }}">← Prev</a>
            @endif
            @if ($messages->nextPageUrl())
                <a class="pm-btn pm-btn--ghost pm-btn--sm" href="{{ $messages->nextPageUrl() }}">Next →</a>
            @endif
        </span>
    </div>
@endsection
