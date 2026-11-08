@extends('layouts.console')

@section('title', __(':name budget sheet', ['name' => $institution->name]))
@section('subtitle', __('Up to the end of :month', ['month' => period_text($period)]))

@section('header-actions')
    <form method="GET" class="flex items-center gap-2">
        <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
        <select name="period" id="period" onchange="this.form.submit()" class="rounded border-rule-strong bg-surface py-1.5 text-sm">
            @unless ($periods->contains($period))
                <option value="{{ $period }}" selected>{{ period_text($period) }}</option>
            @endunless
            @foreach ($periods as $option)
                <option value="{{ $option }}" @selected($option === $period)>{{ period_text($option) }}</option>
            @endforeach
        </select>
    </form>
@endsection

@php
    $sectionNames = ['income' => __('Income'), 'expenditure' => __('Expenditure'), 'capital' => __('Capital')];
@endphp

@section('content')

    <x-breadcrumb :items="[__('All institutions') => route('body.institutions.index'), $institution->name => route('body.institutions.show', ['institution' => $institution, 'period' => $period]), __('Budget sheet') => null]" />

    @if (! $sheet)
        <div class="rounded border border-dashed border-rule-strong bg-surface p-8 text-center">
            <p class="text-sm font-medium text-ink">{{ __(':name has not sent a budget sheet for :month', ['name' => $institution->name, 'month' => period_text($period)]) }}</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-ink-muted">{{ __('Either no budget covers this month in its system, or nothing has arrived here yet.') }}</p>
        </div>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Budget year') }}</p>
                <p class="mt-1 text-lg font-semibold text-ink">{{ $sheet->budget_title }}</p>
                <p class="text-xs text-ink-muted">{{ $sheet->budget_starts->toFormattedDateString() }} – {{ $sheet->budget_ends->toFormattedDateString() }}</p>
            </div>
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Year gone') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ $sheet->yearGoneShare() }}%</p>
            </div>
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Spending budget used') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums {{ $sheet->spentShare() !== null && $sheet->spentShare() > $sheet->yearGoneShare() + 10 ? 'text-rose-700' : 'text-ink' }}">{{ $sheet->spentShare() !== null ? $sheet->spentShare().'%' : '—' }}</p>
                <p class="text-xs text-ink-muted">{{ (float) $sheet->expenditure_budget > 0 ? money_text($sheet->expenditure_to_date).' '.__('of').' '.money_text($sheet->expenditure_budget) : __(':amount spent, nothing budgeted', ['amount' => money_text($sheet->expenditure_to_date)]) }}</p>
            </div>
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Budgeted income received') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ $sheet->receivedShare() !== null ? $sheet->receivedShare().'%' : '—' }}</p>
                <p class="text-xs text-ink-muted">{{ (float) $sheet->income_budget > 0 ? money_text($sheet->income_to_date).' '.__('of').' '.money_text($sheet->income_budget) : __(':amount received, nothing budgeted', ['amount' => money_text($sheet->income_to_date)]) }}</p>
            </div>
        </div>

        @if ((float) $sheet->expenditure_budget == 0.0 && (float) $sheet->income_budget == 0.0)
            <p class="mb-4 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ __('This budget has no amounts allocated yet, so spending can be seen but not measured against a plan.') }}
            </p>
        @endif

        @foreach (collect($document['lines'])->groupBy('section') as $section => $lines)
            <section class="mb-6">
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ $sectionNames[$section] ?? ucfirst($section) }}</h2>
                <div class="overflow-x-auto rounded border border-rule">
                    <table class="min-w-full divide-y divide-rule text-sm">
                        <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                            <tr>
                                <th class="px-4 py-2 font-semibold">{{ __('Line') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Budget') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('This month') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('So far') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ $section === 'income' ? __('Still to come') : __('Left') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Used') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rule bg-surface">
                            @foreach ($lines as $line)
                                @php
                                    $budget = (float) $line['budget'];
                                    $soFar = (float) $line['to_date'];
                                    $used = $budget > 0 ? (int) round($soFar / $budget * 100) : null;
                                    $over = $section !== 'income' && $budget > 0 && $soFar > $budget;
                                    $unplanned = $section !== 'income' && $budget == 0.0 && $soFar > 0;
                                @endphp
                                <tr @class(['bg-surface-sunk font-semibold' => $line['heading'], 'bg-rose-50' => ! $line['heading'] && ($over || $unplanned)])>
                                    <td class="px-4 py-2 {{ $line['heading'] ? 'text-ink' : 'pl-8 text-ink-soft' }}">
                                        @if ($line['code'])<span class="mr-2 text-xs text-ink-muted">{{ $line['code'] }}</span>@endif{{ $line['name'] }}
                                        @if ($over)<span class="ml-2 text-xs font-medium text-rose-700">{{ __('over budget') }}</span>@endif
                                        @if ($unplanned)<span class="ml-2 text-xs font-medium text-rose-700">{{ __('spent with no budget') }}</span>@endif
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($line['budget']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($line['month']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($line['to_date']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums {{ $budget - $soFar < 0 && $section !== 'income' ? 'text-rose-700' : '' }}">{{ $budget > 0 ? money_text($budget - $soFar) : '—' }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-ink-muted">{{ $used !== null ? $used.'%' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        @isset($document['totals'][$section])
                            <tfoot class="border-t-2 border-rule-strong bg-surface-sunk font-medium">
                                <tr>
                                    <td class="px-4 py-2">{{ __('Total') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($document['totals'][$section]['budget']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($document['totals'][$section]['month']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($document['totals'][$section]['to_date']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ (float) $document['totals'][$section]['budget'] > 0 ? money_text((float) $document['totals'][$section]['budget'] - (float) $document['totals'][$section]['to_date']) : '—' }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endisset
                    </table>
                </div>
            </section>
        @endforeach

        <p class="text-xs text-ink-muted">
            {{ __('Amounts are in :currency. This is :name\'s own budget sheet, sent on :date. A heading\'s figures include the lines under it.', ['currency' => $sheet->currency, 'name' => $institution->name, 'date' => $sheet->received_at->toFormattedDateString()]) }}
        </p>
    @endif

@endsection
