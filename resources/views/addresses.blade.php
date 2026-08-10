@extends('postmaster::layout')
@use('STS\Postmaster\Models\EmailAddress')

@section('title', 'Addresses')

@section('content')
    <x-postmaster::filter-panel :action="route('postmaster.addresses')" :filters="$filters">
        @include('postmaster::partials.filters.status', [
            'statuses' => [EmailAddress::STATUS_ACTIVE, EmailAddress::STATUS_SUPPRESSED],
        ])
        @include('postmaster::partials.filters.text', ['name' => 'address', 'label' => 'Address'])
    </x-postmaster::filter-panel>

    @include('postmaster::partials.pager', ['paginator' => $addresses, 'label' => 'addresses'])

    <div class="pm-table-wrap">
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
                            @if ($address->isSuppressed())
                                @include('postmaster::partials.unsuppress', ['address' => $address])
                            @endif
                        </td>
                    </tr>
                @empty
                    @include('postmaster::partials.table-empty', ['note' => 'No addresses match these filters.'])
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $addresses, 'label' => 'addresses'])
@endsection
