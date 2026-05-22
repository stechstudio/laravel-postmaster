{{-- A select filter over a flat list of option values (value is the label).
     Expects $name, $label, $options and $filters; renders nothing when there
     are no options. --}}
@if (! empty($options))
    <div class="pm-field">
        <label>{{ $label }}</label>
        <select name="{{ $name }}" class="pm-select" onchange="this.form.requestSubmit()">
            <option value="">Any</option>
            @foreach ($options as $option)
                <option value="{{ $option }}" @selected(($filters[$name] ?? '') === $option)>{{ $option }}</option>
            @endforeach
        </select>
    </div>
@endif
