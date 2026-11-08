@extends('layouts.console')

@section('title', __('All schools together'))
@section('subtitle', __(':month · :body', ['month' => period_text($period), 'body' => $body?->name ?? platform_name()]))

@section('header-actions')
    <div class="flex items-center gap-2">
        <form method="GET" class="flex items-center gap-2">
            <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
            <select name="period" id="period" onchange="this.form.submit()"
                    class="rounded border border-rule-strong bg-surface px-2 py-1.5 text-sm">
                @foreach (collect(range(0, 17))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m')) as $option)
                    <option value="{{ $option }}" @selected($option === $period)>{{ period_text($option) }}</option>
                @endforeach
            </select>
        </form>
        @can('consolidation.export')
            <a href="{{ route('body.consolidation.export', ['period' => $period]) }}"
               class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
                {{ __('Download PDF') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')

    <p class="mb-4 max-w-3xl text-sm text-ink-soft">
        {{ __('Every school\'s income and spending for the month, added up by account class. A school that sent nothing for the month is left out of the totals and named, never counted as zero.') }}
    </p>

    {{-- Which schools are in the totals comes first, before any total. --}}
    <div class="mb-6 rounded border border-rule bg-surface px-4 py-3">
        <x-coverage :aggregate="$position['income']" />
    </div>

    @php
        $totalLabel = fn ($aggregate) => $aggregate->isComplete()
            ? __('Total')
            : __('Total for :n of :total schools', ['n' => $aggregate->coveredCount(), 'total' => $aggregate->total_sources()]);
    @endphp

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([['income', __('Income')], ['expenditure', __('Spending')]] as [$section, $heading])
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ $heading }}</h2>
                <div class="overflow-x-auto rounded border border-rule">
                    <table class="min-w-full divide-y divide-rule text-sm">
                        <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                            <tr>
                                <th class="px-4 py-2 font-semibold">{{ __('Account class') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Amount') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Schools') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rule bg-surface">
                            @forelse ($$section as $group => $aggregate)
                                <tr>
                                    <td class="px-4 py-2 text-ink-soft">{{ class_label($group, $classNames[$group] ?? null) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $aggregate->formatted() }}</td>
                                    <td class="px-4 py-2 text-right text-xs text-ink-muted">{{ $aggregate->coveredCount() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-3 text-sm italic text-ink-muted">
                                    {{ $position[$section]->hasFigures() ? __('None recorded by the schools that sent figures.') : __('No school sent figures for this month.') }}
                                </td></tr>
                            @endforelse
                        </tbody>
                        @if ($position[$section]->hasFigures())
                            <tfoot class="bg-surface-sunk">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-ink-muted">{{ $totalLabel($position[$section]) }}</th>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ $position[$section]->formatted() }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </section>
        @endforeach
    </div>

    <section class="mt-6 grid gap-4 sm:grid-cols-2">
        @foreach ([['surplus', __('Income less spending')], ['cash_closing', __('Cash at month end')]] as [$key, $label])
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $position[$key]->formatted() }}</p>
                @if ($position[$key]->hasFigures() && ! $position[$key]->isComplete())
                    <p class="mt-1 text-xs text-amber-700">{{ __('From :n of :total schools only.', ['n' => $position[$key]->coveredCount(), 'total' => $position[$key]->total_sources()]) }}</p>
                @endif
            </div>
        @endforeach
    </section>

    {{-- Each school's share, so a total can be traced back to who sent it. --}}
    <section class="mt-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('By school') }}</h2>
        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('School') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Income') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Spending') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Income less spending') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($position['rows'] as $row)
                        <tr>
                            <td class="px-4 py-2">
                                <a href="{{ route('body.institutions.monthly', [$row->institution, $period]) }}" class="font-medium text-ink hover:underline">{{ $row->institution->name }}</a>
                            </td>
                            @foreach (['income', 'expenditure', 'surplus'] as $col)
                                <td class="px-4 py-2 text-right">
                                    <x-figure :figure="$row->{$col}" />
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Named, not merely counted: naming them tells you who to ring. --}}
    @if (! empty($notReporting))
        <section class="mt-6">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
                {{ __('Gone quiet') }}
            </h2>
            <ul class="rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                @foreach ($notReporting as $row)
                    <li>· {{ $row->institution->name }}: {{ $row->never_reported ? __('has never sent anything') : __('nothing received for :days days', ['days' => $row->days_silent]) }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <p class="mt-8 border-t border-rule pt-4 text-xs text-ink-muted">
        {{ __('Added up from the monthly reports the schools sent, not read from their books. Account classes are OHADA classes as each school uses them: 70 to 79 income, 60 to 69 spending. The names are the schools\' own, and where two schools name a class differently both names are shown. Schools may book the same kind of money under different classes, so compare schools by class with care; the daybooks show what the money was.') }}
    </p>

@endsection
