{{-- A from/to date-range filter. Expects $filters. The query params are
     date_from/date_to rather than from/to so the end date can't collide with
     the recipient "To" text filter (both would otherwise submit name="to",
     and the empty date input, being last, would clobber the recipient value). --}}
<div class="pm-field">
    <label>From</label>
    <input type="date" name="date_from" class="pm-input" value="{{ $filters['date_from'] ?? '' }}"
           onchange="this.form.requestSubmit()">
</div>
<div class="pm-field">
    <label>To</label>
    <input type="date" name="date_to" class="pm-input" value="{{ $filters['date_to'] ?? '' }}"
           onchange="this.form.requestSubmit()">
</div>
