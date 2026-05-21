@extends('postmaster::layout')

@section('title', 'Activity')

@section('content')
    @unless ($enabled)
        <div class="pm-card">
            <div class="pm-dim">
                Timeline recording is off, so this stream stays empty. Enable
                <span class="pm-mono">POSTMASTER_RECORD_EVENTS</span> to record events as they arrive.
            </div>
        </div>
    @endunless

    <div class="pm-card" style="padding: 0;" x-data="pmActivity(@js($events), {{ $lastId }})" x-init="start()">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Recipient</th>
                    <th>Provider</th>
                    <th>Message ID</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="event in events" :key="event.id">
                    <tr>
                        <td class="pm-dim" x-text="event.at"></td>
                        <td><span class="pm-badge" :class="'pm-badge--' + tone(event.status)" x-text="event.status"></span></td>
                        <td class="pm-mono" x-text="event.recipient || '—'"></td>
                        <td class="pm-dim" x-text="event.provider || '—'"></td>
                        <td class="pm-mono pm-truncate" x-text="event.messageId || '—'"></td>
                    </tr>
                </template>
                <tr x-show="events.length === 0">
                    <td colspan="5"><div class="pm-empty">Waiting for activity…</div></td>
                </tr>
            </tbody>
        </table>
    </div>
@endsection

@section('scripts')
    <script>
        function pmActivity(initial, lastId) {
            return {
                events: initial,
                lastId: lastId,
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
                                this.events = data.events.concat(this.events).slice(0, 200);
                                this.lastId = data.lastId;
                            }
                        })
                        .catch(() => {});
                },
            };
        }
    </script>
@endsection
