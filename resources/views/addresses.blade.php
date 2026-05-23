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
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($addresses as $address)
                    <tr>
                        <td class="pm-cell-title">{{ $address->address }}</td>
                        <td class="pm-cell-badge">@include('postmaster::partials.badge', ['status' => $address->status])</td>
                        <td class="pm-dim pm-cell-sub">{{ $address->reason ?? '—' }}</td>
                        <td class="pm-dim pm-cell-meta">@include('postmaster::partials.datetime', ['when' => $address->suppressed_at])</td>
                        <td class="pm-cell-action">
                            @if ($address->status === 'suppressed')
                                <form method="POST" action="{{ route('postmaster.addresses.unsuppress') }}"
                                      onsubmit="return confirm('Unsuppress {{ $address->address }}? This clears it both locally and at every configured provider.')">
                                    @csrf
                                    <input type="hidden" name="address" value="{{ $address->address }}">
                                    <button type="submit" class="pm-btn pm-btn--sm pm-btn--ghost">Unsuppress</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr class="pm-row-empty"><td class="pm-cell-full" colspan="5"><div class="pm-empty">No addresses match these filters.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $addresses, 'label' => 'addresses'])
@endsection
