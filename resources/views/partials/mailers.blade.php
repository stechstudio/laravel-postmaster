{{-- The configuration page's mailer table: each mailer worth showing, how
     it's set up, and whether recent sends came back confirmed.

     Params:
       $mailers    : list<STS\Postmaster\Support\MailerStatus>
       $olderSends : bool, whether recent mail predates mailer recording --}}
@use('STS\Postmaster\Support\ConfigurationReport')
<div class="pm-card pm-card--flush">
    <div class="pm-card-head">
        <div>
            <h2 class="pm-section-title">Mailers</h2>
            <div class="pm-setting-note">
                A mailer is working once a send from the last {{ ConfigurationReport::RECENT_DAYS }} days comes back confirmed by its provider's webhook.
                @if ($olderSends)
                    Older mail, sent before Postmaster tracked mailers, isn't counted.
                @endif
            </div>
        </div>
    </div>
    <div class="pm-table-wrap">
        <table class="pm-table pm-mailer-table">
            <thead>
                <tr>
                    <th>Mailer</th>
                    <th>Provider</th>
                    <th>Sending key</th>
                    <th>Webhooks</th>
                    <th>Sync</th>
                    <th>Last {{ ConfigurationReport::RECENT_DAYS }} days</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($mailers as $mailer)
                    @php
                        [$tone, $label, $why] = $mailer->status();
                    @endphp
                    {{-- The pm-cell-* roles lay each row out as a card on phones. --}}
                    <tr>
                        <td class="pm-cell-title">
                            <span class="pm-mono">{{ $mailer->name }}</span>
                            @if ($mailer->role)
                                <span class="pm-mailer-role">{{ $mailer->role }}</span>
                            @endif
                        </td>
                        <td class="pm-cell-sub">
                            <span class="pm-mailer-provider">{{ $mailer->providerLabel() }}</span>
                            @if ($mailer->isSmtp())
                                <span class="pm-mailer-detail">SMTP · <span class="pm-mono">{{ $mailer->host }}</span></span>
                            @elseif ($mailer->configured && $mailer->delivers())
                                <span class="pm-mailer-detail">API @if ($mailer->transport !== $mailer->name) · <span class="pm-mono">{{ $mailer->transport }}</span>@endif</span>
                            @endif
                        </td>
                        @foreach ([$mailer->credential(), $mailer->webhook(), $mailer->sync()] as [$badgeTone, $badge])
                            <td class="pm-cell-detail">
                                @if ($badge === '—')
                                    <span class="pm-dim">—</span>
                                @else
                                    <span class="pm-badge pm-badge--{{ $badgeTone }}">{{ $badge }}</span>
                                @endif
                                @if ($loop->index === 1 && $mailer->lastWebhookAt)
                                    <span class="pm-mailer-detail">Last {{ $mailer->lastWebhookAt->diffForHumans() }}</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="pm-cell-meta">
                            @if ($mailer->sent)
                                {{ number_format($mailer->sent) }} sent
                                <span class="pm-mailer-detail">{{ match (true) {
                                    $mailer->confirmed() === $mailer->sent => 'All confirmed',
                                    $mailer->confirmed() > 0 => number_format($mailer->confirmed()).' confirmed',
                                    default => 'None confirmed',
                                } }}</span>
                            @else
                                <span class="pm-dim">None sent</span>
                            @endif
                        </td>
                        <td class="pm-cell-badge">
                            <span class="pm-badge pm-badge--{{ $tone }}">{{ $label }}</span>
                            <span class="pm-mailer-detail pm-mailer-why">{{ ConfigurationReport::markup($why) }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
