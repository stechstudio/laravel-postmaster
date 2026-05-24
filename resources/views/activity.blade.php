@extends('postmaster::layout')

@section('title', 'Activity')

@section('content')
    @php
        $tenantColumn = config('postmaster.persistence.tenant_column', 'tenant_id');
        $hasTenants = ! empty($tenants);
        $columns = $hasTenants ? 6 : 5;
    @endphp

    @unless ($enabled)
        <div class="pm-card">
            <div class="pm-dim">
                Timeline recording is off, so there is nothing here yet. Enable
                <span class="pm-mono">POSTMASTER_RECORD_EVENTS</span> to record events as they arrive.
            </div>
        </div>
    @endunless

    @php $filtersActive = collect($filters)->except('page')->filter()->isNotEmpty(); @endphp
    <div class="pm-card" x-data="{ filtersOpen: {{ $filtersActive ? 'true' : 'false' }} }">
        @include('postmaster::partials.filters.toggle')
        <form method="GET" action="{{ route('postmaster.activity') }}" class="pm-filters" :class="{ 'is-open': filtersOpen }">
            @include('postmaster::partials.filters.status')
            @include('postmaster::partials.filters.text', ['name' => 'to', 'label' => 'To'])
            @include('postmaster::partials.filters.tenant')
            @include('postmaster::partials.filters.dates')
            <a href="{{ route('postmaster.activity') }}" class="pm-btn pm-btn--ghost">Clear</a>
        </form>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $events, 'label' => 'events'])

    <div class="pm-card" style="padding: 0;">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>To</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Provider</th>
                    @if ($hasTenants)<th>{{ $tenantTerm }}</th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    @php
                        // Lifecycle entries are clickable through to their
                        // message; address-only entries aren't (no message
                        // to drill into).
                        $href = $event->email_message_id ? route('postmaster.messages.show', $event->email_message_id) : null;
                        $recipient = $event->emailMessage?->to_address ?? $event->emailAddress?->address;
                        $subject   = $event->emailMessage?->subject
                            ?? ($event->email_address_id ? '(address activity)' : '—');
                    @endphp
                    <tr @class(['pm-row-link' => $href]) @if ($href) onclick="location.href='{{ $href }}'" @endif>
                        <td class="pm-dim pm-cell-meta">@include('postmaster::partials.datetime', ['when' => $event->occurred_at])</td>
                        <td class="pm-cell-sub">{{ $recipient ?? '—' }}</td>
                        <td class="pm-truncate pm-cell-title">{{ $subject }}</td>
                        <td class="pm-cell-badge">@include('postmaster::partials.badge', ['status' => $event->status])</td>
                        <td class="pm-dim">{{ $event->provider ?? '—' }}</td>
                        @if ($hasTenants)
                            <td class="pm-dim">{{ $tenants[$event->emailMessage?->{$tenantColumn}] ?? '—' }}</td>
                        @endif
                    </tr>
                @empty
                    <tr class="pm-row-empty"><td class="pm-cell-full" colspan="{{ $columns }}"><div class="pm-empty">No activity matches these filters.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $events, 'label' => 'events'])
@endsection
