@extends('postmaster::layout')

@section('title', 'Addresses')

@section('content')
    @php $filtersActive = collect($filters)->except('page')->filter()->isNotEmpty(); @endphp
    <div class="pm-card" x-data="{ filtersOpen: {{ $filtersActive ? 'true' : 'false' }} }">
        @include('postmaster::partials.filters.toggle')
        <form method="GET" action="{{ route('postmaster.addresses') }}" class="pm-filters" :class="{ 'is-open': filtersOpen }">
            <div class="pm-field">
                <label>Status</label>
                <select name="status" class="pm-select" onchange="this.form.requestSubmit()">
                    <option value="">Any</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="suppressed" @selected(($filters['status'] ?? '') === 'suppressed')>Suppressed</option>
                </select>
            </div>
            @include('postmaster::partials.filters.text', ['name' => 'address', 'label' => 'Address'])
            <a href="{{ route('postmaster.addresses') }}" class="pm-btn pm-btn--ghost">Clear</a>
        </form>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $addresses, 'label' => 'addresses'])

    <div class="pm-card" style="padding: 0;">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>Address</th>
                    <th>Status</th>
                    <th>Reason</th>
                    <th>Suppressed</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($addresses as $address)
                    <tr>
                        <td class="pm-mono" data-label="Address">{{ $address->address }}</td>
                        <td data-label="Status">@include('postmaster::partials.badge', ['status' => $address->status])</td>
                        <td class="pm-dim" data-label="Reason">{{ $address->reason ?? '—' }}</td>
                        <td class="pm-dim" data-label="Suppressed">{{ $address->suppressed_at?->format('M j, g:ia') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="pm-empty">No addresses match these filters.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $addresses, 'label' => 'addresses'])
@endsection
