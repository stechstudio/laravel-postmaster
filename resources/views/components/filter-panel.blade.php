{{-- The filter bar above a listing. Filters are secondary to the data, so
     they sit on a recessed well under a hairline rather than in a card of
     equal weight to the table below.

     Collapsed by default on mobile, but open straight away when a filter is
     already applied so it isn't hidden. "page" doesn't count — being on page
     three isn't a filter.

     Props:
       $action  : where the form submits, and where Clear goes back to
       $filters : the current query params, for the open-by-default check --}}
@props(['action', 'filters' => []])
@php $active = collect($filters)->except('page')->filter()->isNotEmpty(); @endphp

<div class="pm-well-panel" x-data="{ filtersOpen: {{ $active ? 'true' : 'false' }} }">
    @include('postmaster::partials.filters.toggle')
    {{-- Filters apply instantly: selects on change, text after a short debounce. --}}
    <form method="GET" action="{{ $action }}" class="pm-filters" :class="{ 'is-open': filtersOpen }">
        {{ $slot }}
        <a href="{{ $action }}" class="pm-btn pm-btn--ghost">Clear</a>
    </form>
</div>
