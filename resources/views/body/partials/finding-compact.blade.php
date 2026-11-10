@php
    $latest = $group->latest;
    $count = $group->findings->count();
    $open = $latest->status === \App\Models\Finding::OPEN;
    $chip = $open ? ([
        'critical' => 'bg-rose-100 text-rose-800',
        'high' => 'bg-amber-100 text-amber-800',
    ][$group->severity] ?? 'bg-surface-sunk text-ink-soft') : 'bg-emerald-50 text-emerald-800';
@endphp

{{-- One line each, so the list can be read at a glance. The full text opens underneath. --}}
<details class="rounded border border-rule bg-surface">
    <summary class="cursor-pointer px-4 py-2.5 text-sm">
        <span class="mr-1.5 inline-block rounded px-1.5 py-0.5 text-xs font-medium {{ $chip }}">{{ $open ? \App\Models\Finding::severityLabel($group->severity) : __('Answered') }}</span>
        <span class="font-medium text-ink">{{ $latest->institution->name }}:</span>
        <span class="text-ink-soft">{{ $latest->title }}</span>
        @if ($count > 1)
            <span class="text-xs text-ink-muted">· {{ __(':count months', ['count' => $count]) }}</span>
        @endif
    </summary>

    <div class="border-t border-rule px-4 py-3 text-sm text-ink-soft">
        <p>{{ $latest->explanation }}</p>

        <ul class="mt-3 divide-y divide-rule">
            @foreach ($group->findings as $finding)
                <li class="flex flex-wrap items-center justify-between gap-2 py-1.5">
                    <span class="min-w-0">
                        @if ($finding->period)
                            <span class="font-medium text-ink">{{ period_text($finding->period) }}</span>
                        @endif
                        @if ($count > 1)
                            <span class="ml-2 text-ink-muted">{{ $finding->title }}</span>
                        @endif
                    </span>
                    @if ($finding->status !== \App\Models\Finding::OPEN)
                        <a href="{{ route('body.findings.show', $finding) }}"
                           class="shrink-0 rounded border border-rule-strong px-2.5 py-1 text-xs font-medium text-ink hover:bg-surface-sunk">{{ __('Read the answer') }}</a>
                    @else
                        @can('finding.resolve')
                            <a href="{{ route('body.findings.show', $finding) }}"
                               class="shrink-0 rounded border border-rule-strong px-2.5 py-1 text-xs font-medium text-ink hover:bg-surface-sunk">{{ __('Answer') }}</a>
                        @endcan
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</details>
