{{-- A tenant filter. Expects $tenants and $filters; renders nothing when no
     tenancy is in use. --}}
@if (! empty($tenants))
    <div class="pm-field">
        <label>Tenant</label>
        <select name="tenant" class="pm-select" onchange="this.form.requestSubmit()">
            <option value="">Any</option>
            @foreach ($tenants as $key => $label)
                <option value="{{ $key }}" @selected((string) ($filters['tenant'] ?? '') === (string) $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
@endif
