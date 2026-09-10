@extends('layouts.console')

@section('title', __('Consolidated position'))
@section('subtitle', __('Calendar month :period · :body', ['period' => $period, 'body' => $body?->name ?? platform_name()]))

@section('header-actions')
    <div class="flex items-center gap-2">
        <form method="GET" class="flex items-center gap-2">
            <select name="period" onchange="this.form.submit()"
                    class="rounded border-rule-strong bg-surface py-1.5 text-sm">
                @foreach (collect(range(0, 17))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m')) as $option)
                    <option value="{{ $option }}" @selected($option === $period)>{{ $option }}</option>
                @endforeach
            </select>
        </form>
        @can('consolidation.export')
            <a href="{{ route('body.consolidation.export', ['period' => $period]) }}"
               class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
                {{ __('Export PDF') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')

    {{-- Coverage first, before any total. --}}
    <div class="mb-6 rounded border border-rule bg-surface px-4 py-3">
        <x-coverage :aggregate="$position['income']" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([['income', __('Income by OHADA group')], ['expenditure', __('Expenditure by OHADA group')]] as [$section, $heading])
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ $heading }}</h2>
                <div class="overflow-hidden rounded border border-rule">
                    <table class="min-w-full divide-y divide-rule text-sm">
                        <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                            <tr>
                                <th class="px-4 py-2 font-semibold">{{ __('OHADA group') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Amount') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Institutions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rule bg-surface">
                            @forelse ($$section as $group => $aggregate)
                                <tr>
                                    <td class="px-4 py-2 font-mono text-xs text-ink-muted">{{ $group }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $aggregate->formatted() }}</td>
                                    <td class="px-4 py-2 text-right text-xs text-ink-muted">{{ $aggregate->coveredCount() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-3 text-sm italic text-ink-muted">{{ __('Nothing reported for this month.') }}</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-surface-sunk">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs uppercase tracking-wide text-ink-muted">{{ __('Total') }}</th>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ $position[$section]->formatted() }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endforeach
    </div>

    <section class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded border border-rule bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Surplus / deficit') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $position['surplus']->formatted() }}</p>
        </div>
        <div class="rounded border border-rule bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Cash at month end') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $position['cash_closing']->formatted() }}</p>
        </div>
    </section>

    {{-- Named, not merely counted. "2 not reporting" tells a bursary head that
         something is missing; naming them tells them who to ring. --}}
    @if (! empty($notReporting))
        <section class="mt-6">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
                {{ __('Excluded from these totals') }}
            </h2>
            <ul class="rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                @foreach ($notReporting as $row)
                    <li>· {{ $row->institution->name }} — {{ $row->never_reported ? __('never reported') : __(':days days silent', ['days' => $row->days_silent]) }}</li>
                @endforeach
            </ul>
        </section>
    @endif

@endsection
