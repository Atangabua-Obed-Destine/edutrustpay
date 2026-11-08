@extends('layouts.console')

@section('title', __('Compare schools'))
@section('subtitle', __(':from to :to', ['from' => period_text($from), 'to' => period_text($to)]))

@section('header-actions')
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <label for="from" class="text-sm text-ink-muted">{{ __('From') }}</label>
        <select name="from" id="from" onchange="this.form.submit()" class="rounded border-rule-strong bg-surface py-1.5 text-sm">
            @foreach ($monthOptions as $option)
                <option value="{{ $option }}" @selected($option === $from)>{{ period_text($option) }}</option>
            @endforeach
        </select>
        <label for="to" class="text-sm text-ink-muted">{{ __('to') }}</label>
        <select name="to" id="to" onchange="this.form.submit()" class="rounded border-rule-strong bg-surface py-1.5 text-sm">
            @foreach ($monthOptions as $option)
                <option value="{{ $option }}" @selected($option === $to)>{{ period_text($option) }}</option>
            @endforeach
        </select>
    </form>
@endsection

@php
    $rows = $comparison['rows'];
    $canRead = auth()->user()->can('daybook.view');
    $percent = fn ($value) => $value === null ? '—' : $value.'%';
@endphp

@section('content')

    <p class="mb-6 max-w-3xl text-sm text-ink-soft">
        {{ __('Built from every line in each daybook. Schools differ in size, so the percentages compare better than the totals. A difference is a reason to ask, not proof that something is wrong.') }}
    </p>

    @if (collect($rows)->contains(fn ($row) => $row->has_data && $row->months_sent < $row->months_total))
        <p class="mb-4 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ __('Not every school has sent every month, so these totals cover different lengths of time. Check "Months sent" before comparing them.') }}
        </p>
    @endif

    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Side by side') }}</h2>
        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('School') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Months sent') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Money in') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Money out') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('In less out') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Fees') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Staff pay') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Other spending') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-ink">
                                @if ($canRead)
                                    <a href="{{ route('body.institutions.daybook', $row->institution) }}" class="underline-offset-2 hover:underline">{{ $row->institution->name }}</a>
                                @else
                                    {{ $row->institution->name }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 {{ $row->months_sent < $row->months_total ? 'font-medium text-amber-700' : 'text-ink-soft' }}">{{ __(':sent of :total', ['sent' => $row->months_sent, 'total' => $row->months_total]) }}</td>
                            @if ($row->has_data)
                                <td class="px-4 py-2 text-right tabular-nums">{{ money_text($row->money_in->value()) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ money_text($row->money_out->value()) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums {{ $row->difference->isNegative() ? 'text-rose-700' : '' }}">{{ money_text($row->difference->value()) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ money_text($row->fees->value()) }}</td>
                                @if ($row->has_payroll || $row->money_out->isZero())
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($row->payroll->value()) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($row->money_out->subtract($row->payroll)->value()) }}</td>
                                @else
                                    <td colspan="2" class="px-4 py-2 text-right text-xs italic text-ink-muted">{{ __('salaries not recorded through payroll') }}</td>
                                @endif
                            @else
                                <td colspan="6" class="px-4 py-2 text-right text-sm italic text-ink-muted">{{ __('no daybook sent for these months') }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                @if ($comparison['totals'])
                    <tfoot class="border-t-2 border-rule-strong bg-surface-sunk font-medium">
                        <tr>
                            <td class="px-4 py-2 text-ink" colspan="2">{{ __('All schools') }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ money_text($comparison['totals']->money_in->value()) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ money_text($comparison['totals']->money_out->value()) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ money_text($comparison['totals']->difference->value()) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ money_text($comparison['totals']->fees->value()) }}</td>
                            @if ($comparison['totals']->payroll_known)
                                <td class="px-4 py-2 text-right tabular-nums">{{ money_text($comparison['totals']->payroll->value()) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ money_text($comparison['totals']->money_out->subtract($comparison['totals']->payroll)->value()) }}</td>
                            @else
                                <td colspan="2" class="px-4 py-2 text-right text-xs font-normal text-ink-muted">{{ __('not every school records salaries through payroll') }}</td>
                            @endif
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('How each school compares') }}</h2>
        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('School') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Income from fees') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Spending on staff pay') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Money in taken as cash') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-ink">{{ $row->institution->name }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $percent($row->fee_share) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $percent($row->payroll_share) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $percent($row->cash_share) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-ink-muted">{{ __('A dash means it cannot be worked out: nothing to take a share of, or salaries not recorded through payroll, in which case they appear under what the school spends most on. The cash share only counts money with a recorded method.') }}</p>
    </section>

    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('What each school spends most on') }}</h2>
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($rows as $row)
                <div class="min-w-0 rounded border border-rule bg-surface p-4">
                    <p class="mb-2 text-sm font-medium text-ink">{{ $row->institution->name }}</p>
                    @forelse ($row->top_spending as $item)
                        <div class="flex items-baseline justify-between gap-3 border-t border-rule py-1.5 text-sm">
                            <span class="min-w-0 text-ink-soft">{{ $item->heading }}</span>
                            <span class="shrink-0 tabular-nums text-ink">{{ money_text($item->amount) }}
                                @if ($item->share !== null)<span class="ml-1 text-xs text-ink-muted">{{ $item->share }}%</span>@endif
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-ink-muted">{{ __('No spending recorded in these months.') }}</p>
                    @endforelse
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-ink-muted">{{ __('Under each school\'s own headings, so the names can differ between schools.') }}</p>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Month by month') }}</h2>
        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('Month') }}</th>
                        @foreach ($rows as $row)
                            <th class="px-4 py-2 text-right font-semibold">{{ $row->institution->name }}<span class="block font-normal normal-case">{{ __('in / out') }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($comparison['monthly'] as $month)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-soft">{{ period_text($month->period) }}</td>
                            @foreach ($rows as $row)
                                @php $cell = $month->cells[$row->institution->id] ?? null; @endphp
                                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                                    @if ($cell)
                                        {{ money_text($cell->money_in) }} / {{ money_text($cell->money_out) }}
                                    @else
                                        <span class="text-xs italic text-ink-muted">{{ __('not sent') }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-ink-muted">{{ __('Amounts are in :currency.', ['currency' => config('platform.currency')]) }}</p>
    </section>

@endsection
