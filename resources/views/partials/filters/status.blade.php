{{-- A lifecycle-status filter. Expects $statuses and $filters. --}}
<div class="pm-field">
    <label>Status</label>
    <select name="status" class="pm-select" onchange="this.form.requestSubmit()">
        <option value="">Any</option>
        @foreach ($statuses as $status)
            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
</div>
