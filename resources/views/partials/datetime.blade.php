{{-- Renders a UTC Carbon as a machine-readable <time> element. The
     dashboard layout's pmTimezone Alpine component finds every .pm-when
     and reformats it into the viewer's chosen timezone (detected or UTC).
     The inner text is the UTC fallback for no-JS contexts.

     Params:
       $when  : Carbon|null
       $style : 'short' (default) → "Jun 23, 2:00pm"
                'long'            → "Jun 23, 2026 2:00pm"
       $dash  : string shown when $when is null (default "—") --}}
@php
    $style = $style ?? 'short';
    $format = $style === 'long' ? 'M j, Y g:ia' : 'M j, g:ia';
@endphp
@if ($when)
    <time class="pm-when" datetime="{{ $when->toIso8601String() }}" data-style="{{ $style }}">{{ $when->format($format) }}</time>
@else
    {{ $dash ?? '—' }}
@endif
