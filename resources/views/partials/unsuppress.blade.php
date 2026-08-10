{{-- The action offered on a suppressed address (the caller checks that).

     Where no provider on the row exposes a suppression-list API, an
     Unsuppress button would lift the local row and leave the provider still
     refusing the address — so we name the dashboards the operator has to
     visit instead of offering a button that only half works. --}}
@if ($address->canApiUnsuppress())
    <x-postmaster::confirm-action
        :action="route('postmaster.addresses.unsuppress')"
        :confirm="'Unsuppress '.$address->address.'? This clears it locally and at every provider that supports it.'"
        label="Unsuppress"
        class="pm-btn--sm pm-btn--ghost">
        <input type="hidden" name="address" value="{{ $address->address }}">
    </x-postmaster::confirm-action>
@else
    @php $manual = $address->providersWithoutApiUnsuppress(); @endphp
    <span class="pm-dim pm-cell-meta" title="No suppression-list API available for {{ implode(', ', $manual) }}.">
        Manage in {{ implode('/', $manual) }}
    </span>
@endif
