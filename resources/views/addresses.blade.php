@extends('postmaster::layout')

@section('title', 'Addresses')

@section('content')
    <div class="pm-card">
        <form method="GET" action="{{ route('postmaster.addresses') }}" class="pm-filters">
            <div class="pm-field">
                <label>Status</label>
                <select name="status" class="pm-select">
                    <option value="">Any</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="suppressed" @selected(($filters['status'] ?? '') === 'suppressed')>Suppressed</option>
                </select>
            </div>
            <div class="pm-field">
                <label>Address</label>
                <input type="text" name="address" class="pm-input" placeholder="starts with…" value="{{ $filters['address'] ?? '' }}">
            </div>
            <button type="submit" class="pm-btn">Filter</button>
            <a href="{{ route('postmaster.addresses') }}" class="pm-btn pm-btn--ghost">Clear</a>
        </form>
    </div>

    <div class="pm-card" style="padding: 0;">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>Address</th>
                    <th>Status</th>
                    <th>Reason</th>
                    <th>Suppressed</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($addresses as $address)
                    <tr>
                        <td class="pm-mono">{{ $address->address }}</td>
                        <td>@include('postmaster::partials.badge', ['status' => $address->status])</td>
                        <td class="pm-dim">{{ $address->reason ?? '—' }}</td>
                        <td class="pm-dim">{{ optional($address->suppressed_at)->format('M j, Y H:i') ?? '—' }}</td>
                        <td style="text-align: right;">
                            <form method="POST" action="{{ route('postmaster.addresses.' . ($address->isSuppressed() ? 'unsuppress' : 'suppress'), array_merge(['address' => $address->getKey()], $filters)) }}">
                                @csrf
                                <button type="submit" class="pm-btn pm-btn--ghost pm-btn--sm">
                                    {{ $address->isSuppressed() ? 'Unsuppress' : 'Suppress' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="pm-empty">No addresses match these filters.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pm-pager">
        <span class="pm-dim">{{ number_format($addresses->total()) }} addresses</span>
        <span class="pm-tabs">
            @if ($addresses->previousPageUrl())
                <a class="pm-btn pm-btn--ghost pm-btn--sm" href="{{ $addresses->previousPageUrl() }}">← Prev</a>
            @endif
            @if ($addresses->nextPageUrl())
                <a class="pm-btn pm-btn--ghost pm-btn--sm" href="{{ $addresses->nextPageUrl() }}">Next →</a>
            @endif
        </span>
    </div>
@endsection
