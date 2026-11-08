@extends('layouts.console')

@section('title', __('Overview'))
@section('subtitle', period_text($period))

@section('header-actions')
    <form method="GET" class="flex items-center gap-2">
        <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
        <select name="period" id="period" onchange="this.form.submit()"
                class="rounded border-rule-strong bg-surface py-1.5 text-sm">
            @foreach ($periodOptions as $option)
                <option value="{{ $option }}" @selected($option === $period)>{{ period_text($option) }}</option>
            @endforeach
        </select>
    </form>
@endsection

@php
    $toneText = ['good' => 'text-emerald-700', 'warn' => 'text-amber-700', 'bad' => 'font-medium text-rose-700', 'muted' => 'text-ink-muted'];
    $toneDot = ['good' => 'bg-emerald-500', 'warn' => 'bg-amber-500', 'bad' => 'bg-rose-600', 'muted' => 'bg-rule-strong'];
    $showTotals = $institutions->count() > 1;
    $total = function ($aggregate) {
        if (! $aggregate->hasFigures()) {
            return __('no figures yet');
        }

        return $aggregate->isComplete()
            ? $aggregate->formatted()
            : __('only :covered of :total sent', ['covered' => $aggregate->coveredCount(), 'total' => $aggregate->total_sources()]);
    };
@endphp

@section('content')

    {{--
        1. HOW ARE MY SCHOOLS, IN WORDS.

        Reporting comes first on purpose. Knowing who has gone quiet is worth more
        than any total (brief section 10), and it is the first thing said here.
    --}}
    @if ($institutions->isEmpty())
        <div class="mb-8 rounded border border-dashed border-rule-strong bg-surface p-8 text-center text-sm text-ink-muted">
            {{ __('No schools are connected yet. Once one is, this page shows how it is doing.') }}
        </div>
    @else
        <section class="mb-8 rounded border border-rule bg-surface px-5 py-4">
            <ul class="space-y-2.5">
                @foreach ($overview->headlines as $line)
                    <li class="flex items-start gap-3 text-[15px] leading-snug text-ink">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $toneDot[$line->tone] }}" aria-hidden="true"></span>
                        @if ($line->href)
                            <a href="{{ $line->href }}" class="underline-offset-2 hover:underline">{{ $line->text }}</a>
                        @else
                            <span>{{ $line->text }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            <details class="mt-4 border-t border-rule pt-3 text-sm text-ink-soft">
                <summary class="cursor-pointer font-medium text-ink-soft">{{ __('New here? How to use this page') }}</summary>
                <p class="mt-2">{{ __('Each :one\'s system sends a summary here by itself every month. Nobody types it in. Go through these once a month:', ['one' => $overview->one]) }}</p>
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    <li>{{ __('Did every report arrive? If not, call that :one first: a silent system tells you nothing.', ['one' => $overview->one]) }}</li>
                    <li>{{ __('Are your rules being followed? Open Compliance to see exactly which fee or rule is out of line.') }}</li>
                    <li>{{ __('Is there anything to look into? These are questions to ask, not proof that something is wrong. Answer each one once you know what happened.') }}</li>
                    <li>{{ __('Then look at the money. Click a :one to see its month and its daybook, or use Compare schools to see them side by side.', ['one' => $overview->one]) }}</li>
                </ol>
                <p class="mt-2">{{ __('This console only sees what is recorded in each system, and it never changes anything there. "Doesn\'t send this" means that system does not report that figure. It does not mean the figure is zero.') }}</p>
            </details>
        </section>

        {{-- 2. ONE ROW PER SCHOOL. --}}
        <section class="mb-8">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Your :many', ['many' => $overview->many]) }}</h2>

            <div class="overflow-x-auto rounded border border-rule">
                <table class="min-w-full divide-y divide-rule text-sm">
                    <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th class="px-4 py-2 font-semibold">{{ ucfirst($overview->one) }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Report for :month', ['month' => period_text($period)]) }}</th>
                            @if ($compliance)
                                <th class="px-4 py-2 font-semibold">{{ __('Your rules') }}</th>
                            @endif
                            <th class="px-4 py-2 font-semibold">{{ __('To look into') }}</th>
                            <th class="px-4 py-2 text-right font-semibold">{{ __('Fees collected so far') }}</th>
                            <th class="px-4 py-2 text-right font-semibold">{{ __('Fees owed') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule bg-surface">
                        @foreach ($overview->rows as $row)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('body.institutions.show', ['institution' => $row->institution, 'period' => $period]) }}"
                                       class="font-medium text-ink underline-offset-2 hover:underline">{{ $row->institution->name }}</a>
                                </td>
                                <td class="px-4 py-2.5 {{ $toneText[$row->report->tone] }}">
                                    {{ $row->report->text }}
                                    @if ($row->report->note)
                                        <span class="block text-xs font-normal text-ink-muted">{{ $row->report->note }}</span>
                                    @endif
                                </td>
                                @if ($compliance)
                                    <td class="px-4 py-2.5 {{ $toneText[$row->rules->tone] }}">
                                        <a href="{{ route('body.compliance.index') }}" class="underline-offset-2 hover:underline">{{ $row->rules->text }}</a>
                                    </td>
                                @endif
                                <td class="px-4 py-2.5 text-ink-soft">
                                    @if ($row->issues->count === 0)
                                        <span class="text-ink-muted">{{ __('nothing') }}</span>
                                    @else
                                        <a href="#look-into" class="underline-offset-2 hover:underline">
                                            {{ $row->issues->count }}
                                            @if ($row->issues->urgent > 0)
                                                <span class="text-xs text-amber-700">({{ __(':count needing action', ['count' => $row->issues->urgent]) }})</span>
                                            @endif
                                        </a>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right"><x-figure :figure="$row->collected" /></td>
                                <td class="px-4 py-2.5 text-right"><x-figure :figure="$row->owed" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-ink-muted">
                {{ __('Amounts are in :currency. Fees are running totals up to the end of the month chosen above. Rules and things to look into are as they stand today.', ['currency' => config('platform.currency')]) }}
            </p>
        </section>
    @endif

    {{-- 3. WHAT TO ASK ABOUT, MOST SERIOUS FIRST. --}}
    <section id="look-into" class="mb-8 scroll-mt-4">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('To look into') }}</h2>
            @can('finding.view')
                <a href="{{ route('body.findings.index') }}" class="text-sm text-ink-muted hover:underline">{{ __('See all') }}</a>
            @endcan
        </div>

        @if ($findingGroups->isEmpty())
            <p class="rounded border border-rule bg-surface px-4 py-3 text-sm text-ink-muted">{{ __('Nothing at the moment.') }}</p>
        @else
            <p class="mb-3 text-sm text-ink-muted">{{ __('The system noticed these and cannot explain them. They are questions to ask, not proof of anything. Click one to read it.') }}</p>
            <div class="space-y-2">
                @foreach ($findingGroups as $group)
                    @include('body.partials.finding-compact', ['group' => $group])
                @endforeach
            </div>
        @endif
    </section>

    {{--
        4. THE MONEY, SCHOOL BY SCHOOL.

        A total is only given when every school sent that figure. A total of the
        schools that happened to send it reads as the whole body's position when it
        is not, so otherwise the cell says how many sent it (brief section 7.4).
    --}}
    <section class="mb-8">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Money for :month', ['month' => period_text($period)]) }}</h2>
            @can('consolidation.view')
                <a href="{{ route('body.consolidation', ['period' => $period]) }}" class="text-sm text-ink-muted hover:underline">{{ __('Full statement') }}</a>
            @endcan
        </div>

        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ ucfirst($overview->one) }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Income') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Spending') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Income less spending') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Cash at month end') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Books') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($position['rows'] as $row)
                        <tr>
                            <td class="px-4 py-2">
                                <a href="{{ route('body.institutions.show', ['institution' => $row->institution, 'period' => $period]) }}"
                                   class="font-medium text-ink underline-offset-2 hover:underline">{{ $row->institution->name }}</a>
                            </td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->income" /></td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->expenditure" /></td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->surplus" /></td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->cash_closing" /></td>
                            <td class="px-4 py-2">
                                @if ($row->balanced === null)
                                    <span class="text-xs text-ink-muted">—</span>
                                @elseif ($row->balanced)
                                    <span class="text-xs text-emerald-700">{{ __('balance') }}</span>
                                @else
                                    {{-- Accepted at ingest on purpose: refusing it would
                                         make this institution look silent rather than alarming. --}}
                                    <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-800">{{ __('do not balance') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                @if ($showTotals)
                    <tfoot class="border-t-2 border-rule-strong bg-surface-sunk font-medium">
                        <tr>
                            <td class="px-4 py-2 text-ink">{{ __('All :many', ['many' => $overview->many]) }}</td>
                            @foreach (['income', 'expenditure', 'surplus', 'cash_closing'] as $key)
                                <td class="px-4 py-2 text-right tabular-nums {{ $position[$key]->isComplete() ? 'text-ink' : 'text-xs font-normal text-ink-muted' }}">{{ $total($position[$key]) }}</td>
                            @endforeach
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    {{-- 5. FEES, SCHOOL BY SCHOOL. --}}
    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Fees up to the end of :month', ['month' => period_text($period)]) }}</h2>

        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ ucfirst($overview->one) }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Billed so far') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Collected so far') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Fees owed') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Owed over 90 days') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($collections['rows'] as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-ink">{{ $row->institution->name }}</td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->expected" /></td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->collected" /></td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->receivables" /></td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->owed_over_90" /></td>
                        </tr>
                    @endforeach
                </tbody>
                @if ($showTotals)
                    <tfoot class="border-t-2 border-rule-strong bg-surface-sunk font-medium">
                        <tr>
                            <td class="px-4 py-2 text-ink">{{ __('All :many', ['many' => $overview->many]) }}</td>
                            @foreach ([$collections['expected'], $collections['collected'], $collections['receivables'], $collections['ageing']['90_plus']] as $aggregate)
                                <td class="px-4 py-2 text-right tabular-nums {{ $aggregate->isComplete() ? 'text-ink' : 'text-xs font-normal text-ink-muted' }}">{{ $total($aggregate) }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    {{-- 6. BUDGETS, FROM EACH SCHOOL'S OWN BUDGET SHEET. --}}
    @if ($budgetSheets->isNotEmpty())
        <section>
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Budgets up to the end of :month', ['month' => period_text($period)]) }}</h2>

            <div class="overflow-x-auto rounded border border-rule">
                <table class="min-w-full divide-y divide-rule text-sm">
                    <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th class="px-4 py-2 font-semibold">{{ ucfirst($overview->one) }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Budget year') }}</th>
                            <th class="px-4 py-2 text-right font-semibold">{{ __('Year gone') }}</th>
                            <th class="px-4 py-2 text-right font-semibold">{{ __('Budget used') }}</th>
                            <th class="px-4 py-2 text-right font-semibold">{{ __('Budgeted income received') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule bg-surface">
                        @foreach ($institutions as $institution)
                            @php $sheet = $budgetSheets->get($institution->id); @endphp
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ route('body.institutions.budget', ['institution' => $institution, 'period' => $period]) }}" class="font-medium text-ink underline-offset-2 hover:underline">{{ $institution->name }}</a>
                                </td>
                                @if ($sheet)
                                    <td class="px-4 py-2 text-ink-soft">{{ $sheet->budget_title }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-ink-soft">{{ $sheet->yearGoneShare() }}%</td>
                                    <td class="px-4 py-2 text-right tabular-nums {{ $sheet->spentShare() !== null && $sheet->spentShare() > $sheet->yearGoneShare() + 10 ? 'font-medium text-rose-700' : '' }}">
                                        {{ $sheet->spentShare() !== null ? $sheet->spentShare().'%' : __('nothing budgeted') }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $sheet->receivedShare() !== null ? $sheet->receivedShare().'%' : __('nothing budgeted') }}</td>
                                @else
                                    <td colspan="4" class="px-4 py-2 text-right text-sm italic text-ink-muted">{{ __('no budget sheet for this month') }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-ink-muted">{{ __('Spending well ahead of the year is shown in red. "Nothing budgeted" means the school has a budget sheet with no amounts allocated.') }}</p>
        </section>
    @elseif ($institutions->isNotEmpty())
        <p class="text-sm text-ink-muted">{{ __('Budgets: no :one has sent a budget sheet for this month yet.', ['one' => $overview->one]) }}</p>
    @endif

@endsection
