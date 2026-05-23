@php $limit = $limit ?? 50; @endphp
<div class="pm-feed" x-data="pmActivityFeed(@js($events), {{ $lastId }}, {{ $limit }})" x-init="start()">
    <template x-for="event in events" :key="event.id">
        <div class="pm-feed-row pm-row-link" @click="window.location = messageUrl(event.messageId)">
            <div class="pm-feed-line">
                <span class="pm-feed-primary" x-text="event.subject || '(no subject)'"></span>
                <span class="pm-badge" :class="'pm-badge--' + tone(event.status)" x-text="event.status"></span>
            </div>
            <div class="pm-feed-line">
                <span class="pm-feed-secondary" x-text="event.to || '—'"></span>
                <span class="pm-feed-meta" x-text="event.at"></span>
            </div>
        </div>
    </template>
    <div x-show="events.length === 0" class="pm-empty">Waiting for activity…</div>
</div>
<script>
    function pmActivityFeed(initial, lastId, limit) {
        return {
            events: initial,
            lastId: lastId,
            limit: limit,
            tones: {
                delivered: 'ok', opened: 'info', clicked: 'info',
                sent: 'muted', accepted: 'muted', sandbox: 'warn', deferred: 'warn',
                bounced: 'bad', dropped: 'bad', complained: 'bad',
            },
            tone(status) {
                return this.tones[status] || 'muted';
            },
            messageUrl(id) {
                return '{{ route('postmaster.messages') }}/' + id;
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
