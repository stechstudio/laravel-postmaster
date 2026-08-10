@extends('postmaster::layout')

@section('title', 'Messages')

@section('content')
    @php $hasTenants = ! empty($tenants); @endphp

    <x-postmaster::filter-panel :action="route('postmaster.messages')" :filters="$filters">
        @include('postmaster::partials.filters.status')
        @include('postmaster::partials.filters.options', ['name' => 'provider', 'label' => 'Provider', 'options' => $providers, 'min' => 2])
        @include('postmaster::partials.filters.options', ['name' => 'tag', 'label' => 'Tag', 'options' => $tags])
        @include('postmaster::partials.filters.text', ['name' => 'to', 'label' => 'To'])
        @include('postmaster::partials.filters.text', ['name' => 'subject', 'label' => 'Subject'])
        @include('postmaster::partials.filters.tenant')
        @include('postmaster::partials.filters.dates')
    </x-postmaster::filter-panel>

    @include('postmaster::partials.pager', ['paginator' => $messages, 'label' => 'messages'])

    {{-- No card around the table: row rules alone are enough separation, and
         a frame would only add an edge to cross before reaching the data. --}}
    <div class="pm-table-wrap">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>To</th>
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
                        <td class="pm-cell-sub">
                            {{ $message->to_address ?? '—' }}
                            @if ($message->recipient_role && $message->recipient_role !== 'to')
                                <span class="pm-role-tag pm-role-tag--{{ $message->recipient_role }}">{{ $message->recipient_role }}</span>
                            @endif
                        </td>
                        {{-- Paperclip rides with the subject, the way every
                             mail client puts it, rather than taking a column
                             of its own that would be empty on most rows. It
                             sits outside the truncating span so a long subject
                             can't clip the one mark that says "this came with
                             something". --}}
                        <td class="pm-cell-title">
                            <span class="pm-subject">
                                <span class="pm-truncate">{{ $message->subject ?? '—' }}</span>
                                @if ($message->carriesFiles())
                                    @include('postmaster::partials.paperclip')
                                @endif
                            </span>
                        </td>
                        <td class="pm-cell-badge">@include('postmaster::partials.badge', ['status' => $message->status])</td>
                        <td class="pm-dim">{{ $message->provider ?? '—' }}</td>
                        @if ($hasTenants)
                            <td class="pm-dim">{{ $tenants[$message->tenantKey()] ?? '—' }}</td>
                        @endif
                        <td class="pm-dim pm-cell-meta">@include('postmaster::partials.datetime', ['when' => $message->sent_at])</td>
                    </tr>
                @empty
                    @include('postmaster::partials.table-empty', ['note' => 'No messages match these filters.'])
                @endforelse
            </tbody>
        </table>
    </div>

    @include('postmaster::partials.pager', ['paginator' => $messages, 'label' => 'messages'])
@endsection
