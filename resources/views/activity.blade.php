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

    <div class="pm-card">
        <form method="GET" action="{{ route('postmaster.activity') }}" class="pm-filters" x-data>
            @include('postmaster::partials.filters.status')
            @include('postmaster::partials.filters.text', ['name' => 'recipient', 'label' => 'Recipient'])
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
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Provider</th>
                    @if ($hasTenants)<th>{{ $tenantTerm }}</th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr class="pm-row-link" onclick="location.href='{{ route('postmaster.messages.show', $event->email_message_id) }}'">
                        <td class="pm-dim">{{ $event->occurred_at?->format('M j, g:ia') ?? '—' }}</td>
                        <td class="pm-mono">{{ $event->emailMessage?->recipient ?? '—' }}</td>
                        <td class="pm-truncate">{{ $event->emailMessage?->subject ?? '—' }}</td>
                        <td>@include('postmaster::partials.badge', ['status' => $event->status])</td>
                        <td class="pm-dim">{{ $event->provider ?? '—' }}</td>
                        @if ($hasTenants)
                            <td class="pm-dim">{{ $tenants[$event->emailMessage?->{$tenantColumn}] ?? '—' }}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $columns }}"><div class="pm-empty">No activity matches these filters.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $events, 'label' => 'events'])
@endsection
