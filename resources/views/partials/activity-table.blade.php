@php $compact = $compact ?? false; $limit = $limit ?? 200; @endphp
<div x-data="pmActivityFeed(@js($events), {{ $lastId }}, {{ $limit }})" x-init="start()">
    <table class="pm-table">
        <thead>
            <tr>
                <th>Time</th>
                <th>Recipient</th>
                <th>Status</th>
                @unless ($compact)
                    <th>Provider</th>
                    <th>Message ID</th>
                @endunless
            </tr>
        </thead>
        <tbody>
            <template x-for="event in events" :key="event.id">
                <tr>
                    <td class="pm-dim" x-text="event.at"></td>
                    <td class="pm-mono" x-text="event.recipient || '—'"></td>
                    <td><span class="pm-badge" :class="'pm-badge--' + tone(event.status)" x-text="event.status"></span></td>
                    @unless ($compact)
                        <td class="pm-dim" x-text="event.provider || '—'"></td>
                        <td class="pm-mono pm-truncate" x-text="event.messageId || '—'"></td>
                    @endunless
                </tr>
            </template>
            <tr x-show="events.length === 0">
                <td colspan="{{ $compact ? 3 : 5 }}"><div class="pm-empty">Waiting for activity…</div></td>
            </tr>
        </tbody>
    </table>
</div>
<script>
    function pmActivityFeed(initial, lastId, limit) {
        return {
            events: initial,
            lastId: lastId,
            limit: limit,
            tones: {
                delivered: 'ok', opened: 'info', clicked: 'info',
                sent: 'muted', accepted: 'muted', deferred: 'warn',
                bounced: 'bad', dropped: 'bad', complained: 'bad',
            },
            tone(status) {
                return this.tones[status] || 'muted';
            },
            start() {
                setInterval(() => this.poll(), 4000);
            },
            poll() {
                fetch('{{ route('postmaster.activity.feed') }}?after=' + this.lastId, {
                    headers: { 'Accept': 'application/json' },
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.events.length) {
                            this.events = data.events.concat(this.events).slice(0, this.limit);
                            this.lastId = data.lastId;
                        }
                    })
                    .catch(() => {});
            },
        };
    }
</script>
