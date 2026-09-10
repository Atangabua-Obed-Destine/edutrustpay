@props([
    'series' => [],
    'height' => 28,
    'width' => 120,
    'label' => null,
])

{{--
    An eighteen-month trend, drawn by hand in SVG.

    NO CHART LIBRARY, deliberately. This console is read on a bursary phone
    hotspot in Bamenda; a chart that arrives only when a CDN answers is a chart
    that is missing exactly when the connection is worst. Inline SVG needs no
    request, renders instantly, and prints — which matters, because the
    consolidated statement gets printed and quoted in meetings.

    `series` accepts either ConsolidationService::trend() rows (objects carrying
    a Figure) or plain ['period' => 'YYYY-MM', 'value' => float|null] pairs.

    A month with no value BREAKS THE LINE rather than being drawn at zero.
    Joining across a gap would draw a confident straight line through a month
    nobody reported — the picture equivalent of the one thing this platform
    exists to prevent. A Figure knows the difference; a float cannot, which is
    why the trend shape is passed through intact rather than flattened first.
--}}

@php
    $points = collect($series)->values()->map(function ($point) {
        // A trend row: the Figure decides whether there is a number at all.
        if (is_object($point) && isset($point->figure)) {
            return [
                'period' => $point->period ?? null,
                'value' => $point->figure->hasValue() ? (float) $point->figure->money()?->value() : null,
            ];
        }

        return ['period' => $point['period'] ?? null, 'value' => $point['value'] ?? null];
    });

    $values = $points->pluck('value')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);

    $min = $values->min() ?? 0.0;
    $max = $values->max() ?? 0.0;
    $span = ($max - $min) ?: 1.0;
    $count = max($points->count() - 1, 1);

    // Split into unbroken runs, so a missing month leaves a real gap.
    $runs = [];
    $run = [];

    foreach ($points as $i => $point) {
        if (($point['value'] ?? null) === null) {
            if (count($run) > 0) {
                $runs[] = $run;
                $run = [];
            }

            continue;
        }

        $x = round($i / $count * ($width - 2) + 1, 2);
        $y = round($height - 2 - (((float) $point['value'] - $min) / $span) * ($height - 4), 2);
        $run[] = $x.','.$y;
    }

    if (count($run) > 0) {
        $runs[] = $run;
    }

    $gaps = $points->filter(fn ($p) => ($p['value'] ?? null) === null)->count();
    $zeroY = $min <= 0 && $max >= 0
        ? round($height - 2 - ((0 - $min) / $span) * ($height - 4), 2)
        : null;
@endphp

@if ($values->isEmpty())
    <span class="text-xs italic text-ink-muted">{{ __('no trend yet') }}</span>
@else
    <svg viewBox="0 0 {{ $width }} {{ $height }}" width="{{ $width }}" height="{{ $height }}"
         role="img" preserveAspectRatio="none"
         aria-label="{{ $label ?? __('Trend over :n months', ['n' => $points->count()]) }}{{ $gaps ? ', '.__(':n months not reported', ['n' => $gaps]) : '' }}">

        {{-- Zero is drawn whenever the series crosses it: a line that dips below
             zero looks identical to one that does not without it. --}}
        @if ($zeroY !== null)
            <line x1="0" y1="{{ $zeroY }}" x2="{{ $width }}" y2="{{ $zeroY }}"
                  stroke="currentColor" stroke-width="0.5" opacity="0.25" />
        @endif

        @foreach ($runs as $run)
            @if (count($run) === 1)
                {{-- A single reported month between two gaps still has to appear. --}}
                <circle cx="{{ explode(',', $run[0])[0] }}" cy="{{ explode(',', $run[0])[1] }}" r="1.6" fill="currentColor" />
            @else
                <polyline points="{{ implode(' ', $run) }}" fill="none"
                          stroke="currentColor" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round" />
            @endif
        @endforeach
    </svg>
@endif
