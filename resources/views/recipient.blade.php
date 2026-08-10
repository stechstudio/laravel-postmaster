@extends('postmaster::layout')

@section('title', 'Emails for '.$label)

@section('content')
    @php $hasTenants = ! empty($tenants); @endphp

    <div>
        <a href="{{ route('postmaster.messages') }}" class="pm-btn pm-btn--ghost">← Back to messages</a>
    </div>

    <div class="pm-card pm-page-head">
        <h1 class="pm-section-title">Emails for {{ $label }}</h1>
        <div class="pm-dim">{{ class_basename($type) }} #{{ $id }}</div>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $messages, 'label' => 'messages'])

    <div class="pm-table-wrap">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Provider</th>
                    @if ($hasTenants)<th>{{ $tenantTerm }}</th>@endif
                    <th>Sent</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($messages as $message)
                    <tr class="pm-row-link" onclick="location.href='{{ route('postmaster.messages.show', $message) }}'">
                        <td class="pm-truncate pm-cell-title">{{ $message->subject ?? '—' }}</td>
                        <td class="pm-cell-badge">@include('postmaster::partials.badge', ['status' => $message->status])</td>
                        <td class="pm-dim pm-cell-sub">{{ $message->provider ?? '—' }}</td>
                        @if ($hasTenants)
                            <td class="pm-dim">{{ $tenants[$message->tenantKey()] ?? '—' }}</td>
                        @endif
                        <td class="pm-dim pm-cell-meta">@include('postmaster::partials.datetime', ['when' => $message->sent_at])</td>
                    </tr>
                @empty
                    @include('postmaster::partials.table-empty', ['note' => 'No messages recorded for this recipient yet.'])
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $messages, 'label' => 'messages'])
@endsection
