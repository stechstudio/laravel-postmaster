@extends('postmaster::layout')

@section('title', 'Addresses')

@section('content')
    <div class="pm-card">
        <form method="GET" action="{{ route('postmaster.addresses') }}" class="pm-filters" x-data>
            <div class="pm-field">
                <label>Status</label>
                <select name="status" class="pm-select" onchange="this.form.requestSubmit()">
                    <option value="">Any</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="suppressed" @selected(($filters['status'] ?? '') === 'suppressed')>Suppressed</option>
                </select>
            </div>
            <div class="pm-field">
                <label>Address</label>
                <input type="text" name="address" class="pm-input" placeholder="starts with…"
                       value="{{ $filters['address'] ?? '' }}"
                       x-on:input.debounce.400ms="($el.value.length >= 3 || $el.value.length === 0) && $el.form.requestSubmit()">
            </div>
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
                        <td class="pm-mono">{{ $address->address }}</td>
                        <td>@include('postmaster::partials.badge', ['status' => $address->status])</td>
                        <td class="pm-dim">{{ $address->reason ?? '—' }}</td>
                        <td class="pm-dim">{{ $address->suppressed_at?->format('M j, g:ia') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="pm-empty">No addresses match these filters.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $addresses, 'label' => 'addresses'])
@endsection
