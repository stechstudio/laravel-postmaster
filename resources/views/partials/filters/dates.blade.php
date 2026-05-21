{{-- A from/to date-range filter. Expects $filters. --}}
<div class="pm-field">
    <label>From</label>
    <input type="date" name="from" class="pm-input" value="{{ $filters['from'] ?? '' }}"
           onchange="this.form.requestSubmit()">
</div>
<div class="pm-field">
    <label>To</label>
    <input type="date" name="to" class="pm-input" value="{{ $filters['to'] ?? '' }}"
           onchange="this.form.requestSubmit()">
</div>
