{{-- A select filter over a flat list of option values (value is the label).
     Expects $name, $label, $options and $filters. Renders nothing unless there
     are at least $min options (default 1): a dropdown that can't narrow the
     list is just clutter on the filter bar. --}}
@if (count($options ?? []) >= ($min ?? 1))
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
