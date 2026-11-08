@extends('layouts.console')

@php
    $month = period_text($period);
    $yearStart = period_text($statement['fiscal_start']);
    $income = $statement['sections']['income'];
    $spending = $statement['sections']['expenditure'];
@endphp

@section('title', __('The year so far'))
@section('subtitle', $institution->name . ' · ' . __('school year starting :month', ['month' => $yearStart]))

@section('header-actions')
    <form method="GET" class="flex items-center gap-2">
        <label for="period" class="text-sm text-ink-muted">{{ __('Up to') }}</label>
        <select name="period" id="period" onchange="this.form.submit()"
                class="rounded border border-rule-strong bg-surface px-2 py-1.5 text-sm">
            @foreach ($history as $row)
                <option value="{{ $row->period }}" @selected($row->period === $period)>{{ period_text($row->period) }}</option>
            @endforeach
            @if ($history->doesntContain('period', $period))
                <option value="{{ $period }}" selected>{{ $month }}</option>
            @endif
        </select>
    </form>

    <a href="{{ route('body.institutions.monthly', [$institution, $period]) }}"
       class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
        {{ __('Everything sent for :month', ['month' => $month]) }}
    </a>

    <button type="button" onclick="window.print()"
            class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
        {{ __('Print') }}
    </button>
@endsection

@section('content')

    <x-breadcrumb :items="[
        __('Institutions') => route('body.institutions.index'),
        $institution->name => route('body.institutions.show', ['institution' => $institution, 'period' => $period]),
        __('The year so far') => null,
    ]" />

    {{--
        The months added up, in the columns the school's own bursar reads every
        month: this month, the months before it, and the year so far. Inventing a
        different layout would mean the body and the school discussing the same
        year in two vocabularies. The budget lives on the budget sheet, which the
        school sends separately and which carries its real budget lines.

        Which months arrived comes first, above any total: the school's own books
        always have every month, the console may not, and a year-so-far that
        quietly skipped a month would be a confident wrong number.
    --}}
    @php $elapsed = count($statement['elapsed']); @endphp

    <div class="mb-4 rounded border-l-4 px-4 py-3 text-sm
                {{ $statement['complete'] ? 'border-emerald-400 bg-emerald-50 text-emerald-900' : 'border-amber-400 bg-amber-50 text-amber-900' }}">
        <p class="font-medium">{{ $annual->coverageStatement($elapsed, $statement['missing']) }}</p>

        @unless ($statement['complete'])
            <p class="mt-1 text-xs">
                {{ __('The totals below leave those months out. They are missing, not zero, so the real totals for the year are higher than shown.') }}
            </p>
        @endunless
    </div>

    {{-- The year at a glance. A month that arrived opens that month. --}}
    <div class="mb-2 flex flex-wrap gap-1.5">
        @foreach ($statement['months'] as $m)
            @php
                $label = \Carbon\Carbon::createFromFormat('!Y-m', $m)->translatedFormat('M Y');
                $isFuture = $m > $statement['period'];
                $isMissing = in_array($m, $statement['missing'], true);
            @endphp

            @if ($isFuture)
                <span class="rounded border border-dashed border-rule px-2 py-1 text-xs text-ink-muted opacity-60"
                      title="{{ __('Still to come') }}">{{ $label }}</span>
            @elseif ($isMissing)
                <span class="rounded border border-amber-300 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-900"
                      title="{{ __('Nothing arrived for this month') }}">{{ $label }}</span>
            @else
                <a href="{{ route('body.institutions.monthly', [$institution, $m]) }}"
                   @class([
                       'rounded border px-2 py-1 text-xs font-medium',
                       'border-emerald-300 bg-emerald-50 text-emerald-900 hover:bg-emerald-100',
                       'ring-2 ring-emerald-500' => $m === $period,
                   ])
                   title="{{ __('Arrived: open everything sent for this month') }}">{{ $label }}</a>
            @endif
        @endforeach
    </div>
    <p class="mb-8 text-xs text-ink-muted">{{ __('Green arrived (click to open it), amber is missing, dashed is still to come.') }}</p>

    @if (! $statement['sends_ledger'] && $statement['reported'] !== [])
        <p class="rounded border border-dashed border-rule-strong bg-surface px-4 py-6 text-center text-sm italic text-ink-muted">
            {{ __(':school sends its monthly report but not its income and spending, so there is nothing here to add up. That is missing information, not a year of zeroes.', ['school' => $institution->name]) }}
        </p>
    @elseif ($statement['reported'] === [])
        <p class="rounded border border-dashed border-rule-strong bg-surface px-4 py-6 text-center text-sm italic text-ink-muted">
            {{ __('Nothing has arrived for this year yet.') }}
        </p>
    @else
        {{-- The year in three numbers, before the detail. --}}
        @php
            $net = (float) $income['totals']->cumulative->value() - (float) $spending['totals']->cumulative->value();
        @endphp
        <div class="mb-8 grid gap-4 sm:grid-cols-3">
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Income so far') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ money_text($income['totals']->cumulative->value()) }}</p>
            </div>
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Spending so far') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ money_text($spending['totals']->cumulative->value()) }}</p>
            </div>
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Income less spending so far') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums {{ $net < 0 ? 'text-rose-700' : '' }}">{{ money_text($net) }}</p>
            </div>
        </div>

        @foreach ([['income', __('Income')], ['expenditure', __('Spending')]] as [$key, $heading])
            @php $section = $statement['sections'][$key]; @endphp

            <section class="mb-8">
                <h2 class="mb-3 font-serif text-lg text-ink">{{ $heading }}</h2>

                @if (empty($section['rows']))
                    <p class="rounded border border-dashed border-rule-strong bg-surface px-4 py-6 text-center text-sm italic text-ink-muted">
                        {{ __('None recorded so far this year.') }}
                    </p>
                @else
                    <div class="overflow-x-auto rounded border border-rule bg-surface">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-rule bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                                    <th class="px-4 py-2 font-semibold">{{ __('Account class') }}</th>
                                    <th class="px-4 py-2 text-right font-semibold">{{ $month }}</th>
                                    <th class="px-4 py-2 text-right font-semibold">{{ __('Earlier months') }}</th>
                                    <th class="px-4 py-2 text-right font-semibold">{{ __('Year so far') }}</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-rule">
                                @foreach ($section['rows'] as $row)
                                    <tr>
                                        <td class="px-4 py-2 text-ink-soft">{{ __('Class :code', ['code' => $row->group]) }}</td>
                                        <td class="px-4 py-2 text-right"><x-figure :figure="$row->month" /></td>
                                        <td class="px-4 py-2 text-right tabular-nums text-ink-soft">{{ money_text($row->brought->value()) }}</td>
                                        <td class="px-4 py-2 text-right font-medium tabular-nums">{{ money_text($row->cumulative->value()) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>

                            <tfoot>
                                <tr class="border-t-2 border-rule-strong font-semibold">
                                    <td class="px-4 py-2">{{ __('Total') }}</td>
                                    <td class="px-4 py-2 text-right"><x-figure :figure="$section['totals']->month" /></td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($section['totals']->brought->value()) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($section['totals']->cumulative->value()) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    @if (! empty($section['brought_missing']))
                        <p class="mt-2 text-xs italic text-amber-700">
                            {{ __('"Earlier months" covers :n of the :total months before :month. Missing: :missing.', [
                                'n' => $section['brought_months'] - count($section['brought_missing']),
                                'total' => $section['brought_months'],
                                'month' => $month,
                                'missing' => implode(', ', array_map('period_text', $section['brought_missing'])),
                            ]) }}
                        </p>
                    @endif
                @endif
            </section>
        @endforeach
    @endif

    <p class="border-t border-rule pt-4 text-xs text-ink-muted">
        {{ __('Added up from the monthly reports this school sent, not read from its books.') }}
        {{ __('Account classes are OHADA classes as the school uses them: 70 to 79 income, 60 to 69 spending.') }}
        @can('daybook.view')
            {{ __('For what the money actually was, open the') }}
            <a href="{{ route('body.institutions.daybook', ['institution' => $institution, 'period' => $period]) }}" class="underline hover:text-ink">{{ __('daybook') }}</a>;
        @endcan
        {{ __('for the budget against spending, the') }}
        <a href="{{ route('body.institutions.budget', ['institution' => $institution, 'period' => $period]) }}" class="underline hover:text-ink">{{ __('budget sheet') }}</a>.
    </p>

@endsection
