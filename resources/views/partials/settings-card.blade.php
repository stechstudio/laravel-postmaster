{{-- A titled group of settings on the configuration page. Each row puts the
     label and the .env variable that sets it on the left, the value and a
     note on the right.

     Params:
       $title, $description : string
       $rows                : list of ConfigurationReport rows
       $lastWebhook         : EmailActivity|null, or false to leave the row out --}}@use('STS\Postmaster\Support\ConfigurationReport')

<div class="pm-card pm-card--flush">
    <div class="pm-card-head">
        <div>
            <h2 class="pm-section-title">{{ $title }}</h2>
            <div class="pm-setting-note">{{ $description }}</div>
        </div>
    </div>
    <dl class="pm-settings">
        @foreach ($rows as $row)
            <div @class(['pm-setting', 'pm-setting--stack' => $row['mono'] && strlen($row['value']) > 40])>
                <dt>
                    {{ $row['label'] }}
                    @if ($row['env'])
                        {{-- Long names break only after an underscore. --}}
                        <span class="pm-setting-env pm-mono">{!! str_replace('_', '_<wbr>', e($row['env'])) !!}</span>
                    @endif
                </dt>
                <dd>
                    @if ($row['tone'])
                        <span class="pm-badge pm-badge--{{ $row['tone'] }}">{{ $row['value'] }}</span>
                    @else
                        {{-- Long code values, such as URLs, break only after a slash. --}}
                        <span @class(['pm-setting-value', 'pm-mono' => $row['mono']])>{!! str_replace('/', '/<wbr>', e($row['value'])) !!}</span>
                    @endif
                    @if ($row['note'])
                        <span class="pm-setting-note">{{ ConfigurationReport::markup($row['note']) }}</span>
                    @endif
                </dd>
            </div>
        @endforeach
        @if ($lastWebhook !== false)
            <div class="pm-setting">
                <dt>Last webhook</dt>
                <dd>
                    @if ($lastWebhook)
                        <span class="pm-setting-value">{{ $lastWebhook->provider }}, {{ $lastWebhook->created_at->diffForHumans() }}</span>
                        <span class="pm-setting-note">@include('postmaster::partials.datetime', ['when' => $lastWebhook->created_at, 'style' => 'long'])</span>
                    @else
                        <span class="pm-badge pm-badge--muted">None recorded</span>
                    @endif
                </dd>
            </div>
        @endif
    </dl>
</div>
