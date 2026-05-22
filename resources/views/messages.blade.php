@extends('postmaster::layout')

@section('title', 'Messages')

@section('content')
    @php
        $tenantColumn = config('postmaster.persistence.tenant_column', 'tenant_id');
        $hasTenants = ! empty($tenants);
        $columns = $hasTenants ? 6 : 5;
        // Collapsed by default on mobile, but open straight away when a
        // filter is already applied so it isn't hidden.
        $filtersActive = collect($filters)->except('page')->filter()->isNotEmpty();
    @endphp

    <div class="pm-card" x-data="{ filtersOpen: {{ $filtersActive ? 'true' : 'false' }} }">
        @include('postmaster::partials.filters.toggle')
        {{-- Filters apply instantly: selects on change, text after a short debounce. --}}
        <form method="GET" action="{{ route('postmaster.messages') }}" class="pm-filters" :class="{ 'is-open': filtersOpen }">
            @include('postmaster::partials.filters.status')
            @include('postmaster::partials.filters.options', ['name' => 'provider', 'label' => 'Provider', 'options' => $providers])
            @include('postmaster::partials.filters.options', ['name' => 'tag', 'label' => 'Tag', 'options' => $tags])
            @include('postmaster::partials.filters.text', ['name' => 'recipient', 'label' => 'Recipient'])
            @include('postmaster::partials.filters.text', ['name' => 'subject', 'label' => 'Subject'])
            @include('postmaster::partials.filters.tenant')
            @include('postmaster::partials.filters.dates')
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
                    @if ($hasTenants)<th>{{ $tenantTerm }}</th>@endif
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
