@props([
    'buckets' => [],
    'total' => null,
])

{{--
    Ageing, as a proportion bar.

    Four numbers in a table tell you what the buckets are. This tells you at a
    glance that most of a debt has been outstanding for more than ninety days,
    which is the only thing anyone actually wants to know from an ageing report.

    Colour runs cool to warm with age, and 90+ is the same rose the console uses
    for findings — because a receivable older than ninety days usually is one.

    `buckets` is a list of ['label' => string, 'value' => float|null].
    A NULL bucket is not drawn and is named in the caption: an institution that
    reports a total but no ageing has told us less than one that reports both,
    and the bar must not imply otherwise.
--}}

@php
    $rows = collect($buckets)->values();
    $known = $rows->filter(fn ($b) => ($b['value'] ?? null) !== null);
    $sum = $known->sum(fn ($b) => (float) $b['value']);
    $missing = $rows->count() - $known->count();

    $shades = ['bg-emerald-500', 'bg-amber-400', 'bg-orange-500', 'bg-rose-500'];
@endphp

@if ($known->isEmpty() || $sum <= 0)
    <p class="text-xs italic text-ink-muted">
        {{ $known->isEmpty() ? __('Ageing not reported.') : __('Nothing outstanding.') }}
    </p>
@else
    <div class="flex h-2 w-full overflow-hidden rounded-full bg-surface-sunk" role="img"
         aria-label="{{ $known->map(fn ($b) => $b['label'].': '.number_format((float) $b['value'], 0, '.', ' '))->implode(', ') }}">
        @foreach ($rows as $i => $bucket)
            @continue(($bucket['value'] ?? null) === null || (float) $bucket['value'] <= 0)
            <span class="{{ $shades[$i] ?? 'bg-ink-muted' }}"
                  style="width: {{ round((float) $bucket['value'] / $sum * 100, 2) }}%"
                  title="{{ $bucket['label'] }} — {{ number_format((float) $bucket['value'], 0, '.', ' ') }}"></span>
        @endforeach
    </div>

    <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-ink-muted">
        @foreach ($rows as $i => $bucket)
            @continue(($bucket['value'] ?? null) === null)
            <span class="inline-flex items-center gap-1">
                <span class="inline-block h-2 w-2 rounded-sm {{ $shades[$i] ?? 'bg-ink-muted' }}"></span>
                {{ $bucket['label'] }}
                <span class="tabular-nums text-ink-soft">{{ round((float) $bucket['value'] / $sum * 100) }}%</span>
            </span>
        @endforeach
    </div>

    @if ($missing > 0)
        <p class="mt-1 text-[11px] italic text-amber-700">
            {{ trans_choice('One bucket was not reported, so this bar covers less than the total.|:count buckets were not reported, so this bar covers less than the total.', $missing, ['count' => $missing]) }}
        </p>
    @endif
@endif
