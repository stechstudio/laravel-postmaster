@extends('postmaster::layout')

@section('title', 'Activity')

@section('content')
    @php $hasTenants = ! empty($tenants); @endphp

    @unless ($enabled)
        <div class="pm-card">
            <div class="pm-dim">
                Timeline recording is off, so there is nothing here yet. Enable
                <span class="pm-mono">POSTMASTER_RECORD_EVENTS</span> to record events as they arrive.
            </div>
        </div>
    @endunless

    <x-postmaster::filter-panel :action="route('postmaster.activity')" :filters="$filters">
        @include('postmaster::partials.filters.status')
        @include('postmaster::partials.filters.text', ['name' => 'to', 'label' => 'To'])
        @include('postmaster::partials.filters.tenant')
        @include('postmaster::partials.filters.dates')
    </x-postmaster::filter-panel>

    @include('postmaster::partials.pager', ['paginator' => $entries, 'label' => 'entries'])

    <div class="pm-table-wrap">
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
                @forelse ($entries as $entry)
                    @php
                        // Lifecycle entries are clickable through to their
                        // message; address-only entries aren't (no message
                        // to drill into).
                        $href = $entry->email_message_id ? route('postmaster.messages.show', $entry->email_message_id) : null;
                        $recipient = $entry->emailMessage?->to_address ?? $entry->emailAddress?->address;
                        $subject   = $entry->emailMessage?->subject
                            ?? ($entry->email_address_id ? '(address activity)' : '—');
                    @endphp
                    <tr @class(['pm-row-link' => $href]) @if ($href) onclick="location.href='{{ $href }}'" @endif>
                        <td class="pm-dim pm-cell-meta">@include('postmaster::partials.datetime', ['when' => $entry->occurred_at])</td>
                        <td class="pm-cell-sub">{{ $recipient ?? '—' }}</td>
                        <td class="pm-truncate pm-cell-title">{{ $subject }}</td>
                        <td class="pm-cell-badge">@include('postmaster::partials.badge', ['status' => $entry->status])</td>
                        <td class="pm-dim">{{ $entry->provider ?? '—' }}</td>
                        @if ($hasTenants)
                            <td class="pm-dim">{{ $tenants[$entry->emailMessage?->tenantKey()] ?? '—' }}</td>
                        @endif
                    </tr>
                @empty
                    @include('postmaster::partials.table-empty', ['note' => 'No activity matches these filters.'])
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $entries, 'label' => 'entries'])
@endsection
