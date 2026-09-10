@props([
    'label',
    'figure' => null,
    'hint' => null,
    'series' => null,
])

{{--
    One headline number, with room for the things a number alone cannot say:
    what it is, where it came from, and which way it has been moving.

    The figure always goes through <x-figure>, so a stat card can no more print
    a zero for a missing month than any other surface can.
--}}

<div class="rounded border border-rule bg-surface px-4 py-3">
    <p class="text-[11px] uppercase tracking-wider text-ink-muted">{{ $label }}</p>

    <p class="mt-1 font-serif text-2xl leading-tight text-ink">
        @if ($figure)
            <x-figure :figure="$figure" />
        @else
            {{ $slot }}
        @endif
    </p>

    @if ($series)
        <div class="mt-2 text-accent">
            <x-sparkline :series="$series" :label="$label" />
        </div>
    @endif

    @if ($hint)
        <p class="mt-1.5 text-[11px] leading-snug text-ink-muted">{{ $hint }}</p>
    @endif
</div>
