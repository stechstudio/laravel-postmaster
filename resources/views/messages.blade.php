@extends('postmaster::layout')

@section('title', 'Messages')

@section('content')
    @php
        $tenantColumn = config('postmaster.persistence.tenant_column', 'tenant_id');
        $hasTenants = ! empty($tenants);
        $columns = $hasTenants ? 6 : 5;
    @endphp

    <div class="pm-card">
        {{-- Filters apply instantly: selects on change, text after a short debounce. --}}
        <form method="GET" action="{{ route('postmaster.messages') }}" class="pm-filters" x-data>
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
                <label>Provider</label>
                <select name="provider" class="pm-select" onchange="this.form.requestSubmit()">
                    <option value="">Any</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider }}" @selected(($filters['provider'] ?? '') === $provider)>{{ ucfirst($provider) }}</option>
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
                <label>Subject</label>
                <input type="text" name="subject" class="pm-input" placeholder="contains…"
                       value="{{ $filters['subject'] ?? '' }}"
                       x-on:input.debounce.400ms="($el.value.length >= 3 || $el.value.length === 0) && $el.form.requestSubmit()">
            </div>
            @if ($hasTenants)
                <div class="pm-field">
                    <label>Tenant</label>
                    <select name="tenant" class="pm-select" onchange="this.form.requestSubmit()">
                        <option value="">Any</option>
                        @foreach ($tenants as $key => $label)
                            <option value="{{ $key }}" @selected((string) ($filters['tenant'] ?? '') === (string) $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
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
            <a href="{{ route('postmaster.messages') }}" class="pm-btn pm-btn--ghost">Clear</a>
        </form>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $messages, 'label' => 'messages'])

    <div class="pm-card" style="padding: 0;">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Provider</th>
                    @if ($hasTenants)<th>Tenant</th>@endif
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
                        @if ($hasTenants)
                            <td class="pm-dim">{{ $tenants[$message->{$tenantColumn}] ?? '—' }}</td>
                        @endif
                        <td class="pm-dim">{{ $message->sent_at?->format('M j, g:ia') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $columns }}"><div class="pm-empty">No messages match these filters.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $messages, 'label' => 'messages'])
@endsection
