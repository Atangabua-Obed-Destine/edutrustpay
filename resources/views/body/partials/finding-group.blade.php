@php
    $severityClasses = [
        'critical' => 'border-rose-300 bg-rose-50 text-rose-900',
        'high'     => 'border-orange-300 bg-orange-50 text-orange-900',
        'medium'   => 'border-amber-300 bg-amber-50 text-amber-900',
        'low'      => 'border-rule-strong bg-surface-sunk text-ink',
    ];
    $open = $group->latest->status === \App\Models\Finding::OPEN;
    $classes = $open ? ($severityClasses[$group->severity] ?? $severityClasses['low']) : $severityClasses['low'];
    $latest = $group->latest;
    $count = $group->findings->count();
@endphp

@if ($count === 1)
    @include('body.partials.finding-row', ['finding' => $latest])
@else
    {{-- One entry per school and control. Each month can still be answered on
         its own, so they are kept and listed inside. --}}
    <details class="rounded border {{ $classes }}">
        <summary class="cursor-pointer px-4 py-3">
            <span class="text-sm font-semibold">{{ $latest->institution->name }}: {{ $latest->title }}</span>
            <span class="ml-2 rounded bg-black/10 px-1.5 py-0.5 text-[11px] font-medium tracking-wide">{{ $open ? \App\Models\Finding::severityLabel($group->severity) : __('Answered') }}</span>
            <span class="ml-2 text-xs font-medium">{{ __(':count months', ['count' => $count]) }}</span>
            <span class="mt-1 block text-xs opacity-70">{{ __('Most recent shown. :months', ['months' => $group->findings->pluck('period')->filter()->sort()->map(fn ($p) => period_text($p))->implode(', ')]) }}</span>
            <span class="mt-1 block text-sm font-normal opacity-90">{{ $latest->explanation }}</span>
        </summary>

        <ul class="divide-y divide-current/10 border-t border-current/20 text-sm">
            @foreach ($group->findings as $finding)
                <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                    <span class="min-w-0">
                        <span class="font-medium">{{ $finding->period ? period_text($finding->period) : '' }}</span>
                        <span class="ml-2 opacity-80">{{ $finding->title }}</span>
                    </span>
                    @if ($finding->status !== \App\Models\Finding::OPEN)
                        <a href="{{ route('body.findings.show', $finding) }}"
                           class="shrink-0 rounded border border-current/30 px-2.5 py-1 text-xs font-medium hover:bg-black/5">{{ __('Read the answer') }}</a>
                    @else
                        @can('finding.resolve')
                            <a href="{{ route('body.findings.show', $finding) }}"
                               class="shrink-0 rounded border border-current/30 px-2.5 py-1 text-xs font-medium hover:bg-black/5">{{ __('Answer') }}</a>
                        @endcan
                    @endif
                </li>
            @endforeach
        </ul>
    </details>
@endif
