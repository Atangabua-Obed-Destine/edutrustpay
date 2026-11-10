@php
    $chips = [
        'complies' => ['bg-emerald-100 text-emerald-800', __('complies')],
        'breach' => ['bg-rose-100 text-rose-800', __('rule broken')],
        'unknown' => ['bg-amber-100 text-amber-800', __('cannot be checked')],
        'waived' => ['bg-surface-sunk text-ink-soft', __('waived')],
        'not_live' => ['bg-surface-sunk text-ink-muted', __('not live yet')],
    ];
    [$chipClass, $chipLabel] = $chips[$state] ?? $chips['unknown'];
@endphp
<span class="inline-block rounded px-1.5 py-0.5 text-xs font-medium {{ $chipClass }}">{{ $chipLabel }}</span>
