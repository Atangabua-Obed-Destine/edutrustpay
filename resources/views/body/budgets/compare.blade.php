@extends('layouts.console')

@section('title', __('Budgets compared'))
@section('subtitle', __('To the end of :month', ['month' => period_text($period)]))

@section('header-actions')
    <x-print-button />
    <form method="GET" class="flex items-center gap-2">
        <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
        <select name="period" id="period" onchange="this.form.submit()" class="rounded border-rule-strong bg-surface py-1.5 text-sm">
            @foreach ($periodOptions as $option)
                <option value="{{ $option }}" @selected($option === $period)>{{ period_text($option) }}</option>
            @endforeach
        </select>
    </form>
@endsection

@php
    $currency = config('platform.currency');

    /*
     * One bar per total: how much of the budget is used (the fill), against how
     * much of the school's budget year has gone (the mark). Every bar also says
     * it in words, so nothing rests on colour.
     */
    $meter = function (?int $share, int $yearGone, bool $warn) {
        $fill = $share === null ? 0 : min(100, $share);

        return '<div class="relative mt-1.5 h-2.5 rounded-full bg-accent-soft" aria-hidden="true">'
            .'<div class="h-full rounded-full '.($warn ? 'bg-rose-600' : 'bg-accent').'" style="width: '.$fill.'%"></div>'
            .'<div class="absolute -top-1 h-[18px] w-0.5 rounded bg-ink" style="left: calc('.$yearGone.'% - 1px)"></div>'
            .'</div>';
    };
@endphp

@section('content')

    <p class="mb-6 max-w-3xl text-sm text-ink-soft">
        {{ __('Each school keeps its own budget for the year in its own system. Here they are set side by side on their totals: what each planned to receive and spend, and how far it has got. For a school\'s lines, open its budget sheet.') }}
    </p>

    @if ($rows->isEmpty())
        <div class="rounded border border-dashed border-rule-strong bg-surface p-8 text-center text-sm text-ink-muted">{{ __('No schools are connected yet.') }}</div>
    @else
        {{-- 1. AT A GLANCE: one card per school. --}}
        <section class="mb-8 grid gap-4 lg:grid-cols-2">
            @foreach ($rows as $row)
                <div class="rounded border border-rule bg-surface p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <a href="{{ route('body.institutions.budget', ['institution' => $row->institution, 'period' => $period]) }}"
                           class="font-serif text-lg text-ink underline-offset-2 hover:underline">{{ $row->institution->name }}</a>
                        @if ($row->sheet)
                            <span class="text-xs text-ink-muted">{{ __(':title budget · :gone% of the year gone', ['title' => $row->sheet->budget_title, 'gone' => $row->year_gone]) }}</span>
                        @endif
                    </div>

                    @if (! $row->sheet)
                        <p class="mt-3 text-sm italic text-ink-muted">{{ __('No budget sheet for :month. Either no budget covers this month, or it has not arrived yet.', ['month' => period_text($period)]) }}</p>
                    @else
                        <dl class="mt-4 space-y-4 text-sm">
                            @foreach ([
                                ['income', __('Income received'), $row->income_behind, __('behind the year')],
                                ['spending', __('Spending used'), $row->spending_ahead, __('ahead of the year')],
                                ['capital', __('Capital used'), false, ''],
                            ] as [$key, $label, $warn, $warnText])
                                @php $part = $row->{$key}; @endphp
                                <div>
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <dt class="font-medium text-ink">{{ $label }}</dt>
                                        <dd class="tabular-nums text-ink-soft">
                                            @if ($part['share'] === null)
                                                <span class="italic text-ink-muted">{{ __('nothing budgeted') }}</span>
                                            @else
                                                <span class="font-semibold text-ink">{{ $part['share'] }}%</span>
                                                {{ __(':done of :budget', ['done' => money_text($part['to_date']), 'budget' => money_text($part['budget'])]) }}
                                                @if ($warn)
                                                    <span class="ml-1 rounded bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-800">{{ $warnText }}</span>
                                                @endif
                                            @endif
                                        </dd>
                                    </div>
                                    @if ($part['share'] !== null)
                                        {!! $meter($part['share'], $row->year_gone, $warn) !!}
                                    @endif
                                </div>
                            @endforeach
                        </dl>

                        <div class="mt-4 grid grid-cols-2 gap-3 border-t border-rule pt-3 text-sm">
                            <div>
                                <p class="text-xs text-ink-muted">{{ __('Planned for the year') }}</p>
                                <p class="font-semibold tabular-nums {{ $row->planned < 0 ? 'text-rose-700' : 'text-ink' }}">{{ $row->planned < 0 ? __('deficit') : __('surplus') }} {{ money_text(abs($row->planned)) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-ink-muted">{{ __('So far') }}</p>
                                <p class="font-semibold tabular-nums {{ $row->so_far < 0 ? 'text-rose-700' : 'text-ink' }}">{{ $row->so_far < 0 ? __('deficit') : __('surplus') }} {{ money_text(abs($row->so_far)) }}</p>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </section>
        <p class="-mt-5 mb-8 flex items-center gap-2 text-xs text-ink-muted">
            <span class="inline-block h-3 w-0.5 rounded bg-ink" aria-hidden="true"></span>
            {{ __('The mark on each bar is how much of that school\'s budget year has gone. Spending well past it is shown in red and said in words.') }}
        </p>

        {{-- 2. THE TOTALS, IN ONE TABLE. --}}
        <section>
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('The totals side by side') }}</h2>
            <div class="overflow-x-auto rounded border border-rule">
                <table class="min-w-full divide-y divide-rule text-sm [&_td]:whitespace-nowrap [&_th]:whitespace-nowrap">
                    <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th rowspan="2" class="px-4 py-2 align-bottom font-semibold">{{ __('School') }}</th>
                            <th rowspan="2" class="px-4 py-2 align-bottom font-semibold">{{ __('Budget year') }}</th>
                            <th colspan="2" class="border-l border-rule px-4 pt-2 text-center font-semibold">{{ __('Income') }}</th>
                            <th colspan="2" class="border-l border-rule px-4 pt-2 text-center font-semibold">{{ __('Spending') }}</th>
                            <th colspan="2" class="border-l border-rule px-4 pt-2 text-center font-semibold">{{ __('Capital') }}</th>
                            <th colspan="2" class="border-l border-rule px-4 pt-2 text-center font-semibold">{{ __('Income less spending and capital') }}</th>
                        </tr>
                        <tr>
                            @foreach ([__('Budgeted'), __('So far'), __('Budgeted'), __('So far'), __('Budgeted'), __('So far'), __('Planned'), __('So far')] as $i => $heading)
                                <th class="{{ $i % 2 === 0 ? 'border-l border-rule' : '' }} px-4 pb-2 text-right font-semibold">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule bg-surface">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-4 py-2 font-medium text-ink">{{ $row->institution->name }}</td>
                                @if (! $row->sheet)
                                    <td colspan="9" class="px-4 py-2 text-sm italic text-ink-muted">{{ __('no budget sheet for this month') }}</td>
                                @else
                                    <td class="whitespace-nowrap px-4 py-2 text-ink-soft">{{ $row->sheet->budget_title }} <span class="block text-xs text-ink-muted">{{ __(':gone% gone', ['gone' => $row->year_gone]) }}</span></td>
                                    @foreach (['income', 'spending', 'capital'] as $key)
                                        <td class="border-l border-rule px-4 py-2 text-right tabular-nums">{{ money_text($row->{$key}['budget']) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums {{ $key === 'spending' && $row->spending_ahead ? 'font-semibold text-rose-700' : 'text-ink-soft' }}">
                                            {{ money_text($row->{$key}['to_date']) }}
                                            @if ($row->{$key}['share'] !== null)<span class="block text-xs text-ink-muted">{{ $row->{$key}['share'] }}%</span>@endif
                                        </td>
                                    @endforeach
                                    <td class="border-l border-rule px-4 py-2 text-right tabular-nums {{ $row->planned < 0 ? 'text-rose-700' : '' }}">{{ money_text($row->planned) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums {{ $row->so_far < 0 ? 'text-rose-700' : 'text-ink-soft' }}">{{ money_text($row->so_far) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($totals && $rows->count() > 1)
                        <tfoot class="border-t-2 border-rule-strong bg-surface-sunk font-medium">
                            <tr>
                                <td class="px-4 py-2 text-ink" colspan="2">
                                    {{ $totals->covered === $totals->of ? __('All :count schools', ['count' => $totals->of]) : __('Total for :covered of :total schools', ['covered' => $totals->covered, 'total' => $totals->of]) }}
                                </td>
                                @foreach (['income', 'spending', 'capital'] as $key)
                                    <td class="border-l border-rule px-4 py-2 text-right tabular-nums">{{ money_text($totals->{$key}['budget']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($totals->{$key}['to_date']) }}</td>
                                @endforeach
                                <td class="border-l border-rule px-4 py-2 text-right tabular-nums">{{ money_text($totals->planned) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ money_text($totals->so_far) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <p class="mt-2 text-xs text-ink-muted">
                {{ __('Amounts are in :currency. "So far" runs from the start of each school\'s budget year to the end of the month chosen above.', ['currency' => $currency]) }}
                @if ($totals?->years_differ)
                    {{ __('These schools\' budget years are not the same, so their totals cover different twelve months.') }}
                @endif
                @if ($totals && $totals->covered < $totals->of)
                    {{ __('The total leaves out the schools with no budget sheet for this month.') }}
                @endif
            </p>
        </section>
    @endif

@endsection
