@php
    $tones = [
        'delivered'  => 'ok',
        'opened'     => 'info',
        'clicked'    => 'info',
        'sent'       => 'muted',
        'accepted'   => 'muted',
        'deferred'   => 'warn',
        'bounced'    => 'bad',
        'dropped'    => 'bad',
        'complained' => 'bad',
        'active'     => 'ok',
        'suppressed' => 'bad',
    ];
    $tone = $tones[$status ?? ''] ?? 'muted';
@endphp
<span class="pm-badge pm-badge--{{ $tone }}">{{ $status ?? '—' }}</span>
