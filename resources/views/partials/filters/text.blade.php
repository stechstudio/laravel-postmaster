{{-- A debounced "contains" text filter. Expects $name, $label, and $filters. --}}
<div class="pm-field">
    <label>{{ $label }}</label>
    <input type="text" name="{{ $name }}" class="pm-input" placeholder="{{ $placeholder ?? 'contains…' }}"
           value="{{ $filters[$name] ?? '' }}"
           x-on:input.debounce.400ms="($el.value.length >= 3 || $el.value.length === 0) && $el.form.requestSubmit()">
</div>
