{{-- A tenant filter. Expects $tenants, $tenantTerm and $filters. Renders
     nothing unless more than one tenant has sent mail — with a single tenant
     the filter could only ever select everything. --}}
@if (count($tenants ?? []) > 1)
    <div class="pm-field">
        <label>{{ $tenantTerm }}</label>
        <select name="tenant" class="pm-select" onchange="this.form.requestSubmit()">
            <option value="">Any</option>
            @foreach ($tenants as $key => $label)
                <option value="{{ $key }}" @selected((string) ($filters['tenant'] ?? '') === (string) $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
@endif
