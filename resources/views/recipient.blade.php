@extends('postmaster::layout')

@section('title', 'Emails for '.$label)

@section('content')
    @php
        $tenantColumn = config('postmaster.persistence.tenant_column', 'tenant_id');
        $hasTenants = ! empty($tenants);
        $columns = $hasTenants ? 5 : 4;
    @endphp

    <div>
        <a href="{{ route('postmaster.messages') }}" class="pm-btn pm-btn--ghost">← Back to messages</a>
    </div>

    <div class="pm-card">
        <h1 class="pm-section-title" style="margin: 0;">Emails for {{ $label }}</h1>
        <div class="pm-dim" style="margin-top: 4px;">{{ class_basename($type) }} #{{ $id }}</div>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $messages, 'label' => 'messages'])

    <div class="pm-card" style="padding: 0;">
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
                            <td class="pm-dim">{{ $tenants[$message->{$tenantColumn}] ?? '—' }}</td>
                        @endif
                        <td class="pm-dim pm-cell-meta">{{ $message->sent_at?->format('M j, g:ia') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr class="pm-row-empty"><td class="pm-cell-full" colspan="{{ $columns }}"><div class="pm-empty">No messages recorded for this recipient yet.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $messages, 'label' => 'messages'])
@endsection
