@php
    $severityClasses = [
        'critical' => 'border-rose-300 bg-rose-50 text-rose-900',
        'high'     => 'border-orange-300 bg-orange-50 text-orange-900',
        'medium'   => 'border-amber-300 bg-amber-50 text-amber-900',
        'low'      => 'border-rule-strong bg-surface-sunk text-ink',
    ];
    $classes = $severityClasses[$finding->severity] ?? $severityClasses['low'];
@endphp

<div class="rounded border px-4 py-3 {{ $classes }}">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="text-sm font-semibold">
                {{ $finding->title }}
                <span class="ml-2 rounded bg-black/10 px-1.5 py-0.5 text-[11px] font-medium">
                    {{ \App\Models\Finding::severityLabel($finding->severity) }}
                </span>
            </p>

            {{-- The explanation is stored rather than generated at render time.
                 A restated period rewrites the figures underneath, and a finding
                 that silently rewords itself afterwards is useless in an audit. --}}
            <p class="mt-1 text-sm opacity-90">{{ $finding->explanation }}</p>

            <p class="mt-1 text-xs opacity-70">
                {{ $finding->institution->name }}
                @if ($finding->period) · {{ period_text($finding->period) }} @endif
                @if ($finding->occurrences > 1)
                    · {{ trans_choice('{1}seen once|[2,*]seen :count times', $finding->occurrences, ['count' => $finding->occurrences]) }}
                @endif
            </p>
        </div>

        @if (! ($hideAnswer ?? false))
        @can('finding.resolve')
            <a href="{{ route('body.findings.show', $finding) }}"
               class="shrink-0 rounded border border-current/30 px-2.5 py-1 text-xs font-medium hover:bg-black/5">
                {{ __('Answer') }}
            </a>
        @endcan
        @endif
    </div>
</div>
