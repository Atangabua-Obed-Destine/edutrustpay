@extends('layouts.console')

@section('title', __('Year to date'))
@section('subtitle', $institution->name . ' · ' . __('fiscal year opening :month', ['month' => $statement['fiscal_start']]))

@section('header-actions')
    <form method="GET" class="flex items-center gap-2">
        <label for="period" class="text-sm text-ink-muted">{{ __('As at') }}</label>
        <select name="period" id="period" onchange="this.form.submit()"
                class="rounded border border-rule-strong bg-surface px-2 py-1.5 text-sm">
            @foreach ($history as $row)
                <option value="{{ $row->period }}" @selected($row->period === $period)>{{ $row->period }}</option>
            @endforeach
            @if ($history->doesntContain('period', $period))
                <option value="{{ $period }}" selected>{{ $period }}</option>
            @endif
        </select>
    </form>

    <a href="{{ route('body.institutions.monthly', [$institution, $period]) }}"
       class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
        {{ __('This month in full') }}
    </a>

    <button type="button" onclick="window.print()"
            class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
        {{ __('Print') }}
    </button>
@endsection

@section('content')

    <x-breadcrumb :items="[
        __('Institutions') => route('body.institutions.index'),
        $institution->name => route('body.institutions.show', $institution),
        __('Year to date') => null,
    ]" />

    {{--
        This is deliberately the same five-column shape the institution's own
        bursar reads every month in their daybook — budget, this month, brought
        forward, cumulative, balance. Inventing a different layout here would
        mean the body and the institution discussing the same year in two
        different vocabularies.

        The budget column is absent until the contract carries a year-to-date
        budget block; see the note beneath the table rather than a blank column
        that looks like a figure nobody filled in.
    --}}

    {{-- ══════════════════════════════════════════════════════════════
         Coverage first, and above the figures.

         The institution's own daybook always has all of its own months, so it
         never has to ask whether the year is complete. This does. A cumulative
         that quietly skipped an unreported month would be a confident wrong
         number — exactly what this platform exists to prevent — so the gap is
         stated before anyone reads a total, not in a footnote after.
         ══════════════════════════════════════════════════════════════ --}}
    @php $elapsed = count($statement['elapsed']); @endphp

    <div class="mb-6 rounded border-l-4 px-4 py-3 text-sm
                {{ $statement['complete'] ? 'border-emerald-400 bg-emerald-50 text-emerald-900' : 'border-amber-400 bg-amber-50 text-amber-900' }}">
        <p class="font-medium">{{ $annual->coverageStatement($elapsed, $statement['missing']) }}</p>

        @unless ($statement['complete'])
            <p class="mt-1 text-xs">
                {{ __('Every cumulative figure below excludes those months. They are an absence of information, not zero — so the year-to-date totals are a floor, not a measurement.') }}
            </p>
        @endunless
    </div>

    {{-- The year at a glance: which months arrived and which did not. Twelve
         cells say more than a sentence, and they are the reason somebody
         clicks through to a specific month. --}}
    <div class="mb-8 flex flex-wrap gap-1.5">
        @foreach ($statement['months'] as $month)
            @php
                $isFuture = $month > $statement['period'];
                $isMissing = in_array($month, $statement['missing'], true);
            @endphp

            @if ($isFuture)
                <span class="rounded border border-dashed border-rule px-2 py-1 text-xs text-ink-muted opacity-50"
                      title="{{ __('Not yet elapsed') }}">{{ $month }}</span>
            @elseif ($isMissing)
                <span class="rounded border border-amber-300 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-900"
                      title="{{ __('No report received') }}">{{ $month }}</span>
            @else
                <a href="{{ route('body.institutions.monthly', [$institution, $month]) }}"
                   class="rounded border border-emerald-300 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-900 hover:bg-emerald-100"
                   title="{{ __('Reported — open this month\'s return') }}">{{ $month }}</a>
            @endif
        @endforeach
    </div>

    @foreach ([['income', __('Income')], ['expenditure', __('Expenditure')]] as [$key, $heading])
        @php $section = $statement['sections'][$key]; @endphp

        <section class="mb-8">
            <h2 class="mb-3 font-serif text-lg text-ink">{{ $heading }}</h2>

            @if (empty($section['rows']))
                <p class="rounded border border-dashed border-rule-strong bg-surface px-4 py-6 text-center text-sm italic text-ink-muted">
                    {{ __('Nothing has been reported under :section in this fiscal year.', ['section' => mb_strtolower($heading)]) }}
                </p>
            @else
                <div class="overflow-x-auto rounded border border-rule bg-surface">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-rule bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                                <th class="px-4 py-2 font-semibold">{{ __('OHADA group') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __(':period', ['period' => $statement['period']]) }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Brought forward') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Cumulative') }}</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-rule">
                            @foreach ($section['rows'] as $row)
                                <tr>
                                    <td class="px-4 py-2">
                                        <span class="font-mono text-xs text-ink-muted">{{ $row->group }}</span>
                                        <span class="ml-2 text-ink-soft">{{ ohada_group_name($row->group) }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-right"><x-figure :figure="$row->month" /></td>
                                    <td class="px-4 py-2 text-right tabular-nums text-ink-soft">
                                        {{ number_format((float) $row->brought->value(), 0, '.', ' ') }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-medium tabular-nums">
                                        {{ number_format((float) $row->cumulative->value(), 0, '.', ' ') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>

                        <tfoot>
                            <tr class="border-t-2 border-rule-strong font-semibold">
                                <td class="px-4 py-2">{{ __('Total :section', ['section' => mb_strtolower($heading)]) }}</td>
                                <td class="px-4 py-2 text-right"><x-figure :figure="$section['totals']->month" /></td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ number_format((float) $section['totals']->brought->value(), 0, '.', ' ') }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ number_format((float) $section['totals']->cumulative->value(), 0, '.', ' ') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if (! empty($section['brought_missing']))
                    <p class="mt-2 text-xs italic text-amber-700">
                        {{ __('Brought forward covers :n of :total earlier months. Missing: :missing.', [
                            'n' => $section['brought_months'] - count($section['brought_missing']),
                            'total' => $section['brought_months'],
                            'missing' => implode(', ', $section['brought_missing']),
                        ]) }}
                    </p>
                @endif
            @endif
        </section>
    @endforeach

    <p class="border-t border-rule pt-4 text-xs text-ink-muted">
        {{ __('Assembled from the monthly returns this institution filed — not read from its books. :platform holds no transactions and is never the authority on an institution\'s ledger; for line detail, open the institution\'s own system.', ['platform' => platform_name()]) }}
        <br>
        {{ __('A budget column will appear here once the contract carries a year-to-date budget block. A twelfth of an annual budget is not what anyone budgeted, so nothing is shown rather than something plausible.') }}
    </p>

@endsection
