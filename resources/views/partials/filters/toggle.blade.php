{{-- The mobile "Filters" trigger. Toggles filtersOpen on the enclosing card;
     the filter form is collapsed by default on small screens. --}}
<button type="button" class="pm-filters-toggle" @click="filtersOpen = ! filtersOpen" :aria-expanded="filtersOpen">
    <span>Filters</span>
    <svg class="pm-filters-chevron" :class="{ 'is-open': filtersOpen }" width="14" height="14"
         viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 8l5 5 5-5"/>
    </svg>
</button>
