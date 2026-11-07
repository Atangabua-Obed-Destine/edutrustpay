@extends('layouts.console')

@section('title', __('Overview'))
@section('subtitle', __('Calendar month :period', ['period' => $period]))

@section('header-actions')
    <form method="GET" class="flex items-center gap-2">
        <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
        <select name="period" id="period" onchange="this.form.submit()"
                class="rounded border-rule-strong bg-surface py-1.5 text-sm">
            @foreach ($periodOptions as $option)
                <option value="{{ $option }}" @selected($option === $period)>{{ $option }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')

    {{--
        1. WHO HAS NOT REPORTED.

        First on the page, before any total. "Which institutions have not
        reported, and for how long" is worth more than the consolidated figure
        (brief §4.5), and a layout that puts a healthy-looking number above it
        has already misled the reader.
    --}}
    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
            {{ __('Not reporting') }}
        </h2>

        @if (empty($notReporting))
            <div class="rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800
                       ">
                {{ __('Every institution is reporting within its threshold.') }}
            </div>
        @else
            <div class="overflow-hidden rounded border border-rose-200">
                <table class="min-w-full divide-y divide-rose-200 text-sm">
                    <thead class="bg-rose-50 text-left text-xs uppercase tracking-wide text-rose-800">
                        <tr>
                            <th class="px-4 py-2 font-semibold">{{ __('Institution') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Silent for') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Last period received') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Threshold') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rose-100 bg-surface">
                        @foreach ($notReporting as $row)
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ route('body.institutions.show', $row->institution) }}"
                                       class="font-medium text-ink hover:underline">
                                        {{ $row->institution->name }}
                                    </a>
                                    <span class="ml-2 text-xs text-ink-muted">{{ $row->institution->system }}</span>

                                    @if ($row->alive_but_silent)
                                        {{-- The server is alive and someone chose not to send figures.
                                             A different problem from a machine that has stopped. --}}
                                        <span class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800
                                                    ">
                                            {{ __('heartbeat only') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 font-medium text-rose-700">
                                    @if ($row->never_reported)
                                        {{-- Never once pushed: a failed onboarding, not a lapse. --}}
                                        {{ __('never reported') }}
                                    @else
                                        {{ trans_choice('{1}1 day|[2,*]:count days', $row->days_silent, ['count' => $row->days_silent]) }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-ink-muted">
                                    {{ $row->last_period ?? '—' }}
                                </td>
                                <td class="px-4 py-2 text-ink-muted">{{ $row->threshold }} {{ __('days') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Rules the body set, for those who can see them. --}}
    @if ($compliance && $compliance['regulations']->isNotEmpty())
        <section class="mb-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Compliance') }}</h2>
                <a href="{{ route('body.compliance.index') }}" class="text-sm text-ink-muted hover:underline">{{ __('See the board') }}</a>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ([['breach', __('Breaches'), 'text-rose-700'], ['unknown', __('Cannot be checked'), 'text-amber-700'], ['complies', __('Complying'), 'text-emerald-700']] as [$key, $label, $colour])
                    <a href="{{ route('body.compliance.index') }}" class="rounded border border-rule bg-surface p-4 hover:bg-surface-sunk">
                        <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums {{ $compliance['totals'][$key] > 0 ? $colour : '' }}">{{ $compliance['totals'][$key] }}</p>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 2. FINDINGS — controls that fired, most severe first. --}}
    <section class="mb-8">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-muted">
                {{ __('Findings') }}
            </h2>
            @can('finding.view')
                <a href="{{ route('body.findings.index') }}" class="text-sm text-ink-muted hover:underline">
                    {{ __('All findings') }}
                </a>
            @endcan
        </div>

        @if ($findingGroups->isEmpty())
            <p class="rounded border border-rule bg-surface px-4 py-3 text-sm text-ink-muted">
                {{ __('No open findings.') }}
            </p>
        @else
            <div class="space-y-2">
                @foreach ($findingGroups as $group)
                    @include('body.partials.finding-group', ['group' => $group])
                @endforeach
            </div>
        @endif
    </section>

    {{--
        3 & 4. COVERAGE, THEN THE CONSOLIDATED POSITION.

        The coverage statement is rendered from the same aggregate that produced
        each total, so it cannot be left off or drift away from the number.
    --}}
    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
            {{ __('Consolidated position') }}
        </h2>

        <div class="mb-4 rounded border border-rule bg-surface px-4 py-3">
            <x-coverage :aggregate="$position['income']" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                'income' => __('Income'),
                'expenditure' => __('Expenditure'),
                'surplus' => __('Surplus / deficit'),
                'cash_closing' => __('Cash at month end'),
            ] as $key => $label)
                <div class="rounded border border-rule bg-surface p-4">
                    <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-ink">
                        {{ $position[$key]->formatted() }}
                        @if ($position[$key]->hasFigures())
                            <span class="text-sm font-normal text-ink-muted">{{ config('platform.currency') }}</span>
                        @endif
                    </p>
                    @unless ($position[$key]->isUnqualified())
                        <p class="mt-1 text-xs font-medium text-amber-700">
                            {{ __(':covered of :total', ['covered' => $position[$key]->coveredCount(), 'total' => $position[$key]->total_sources()]) }}
                        </p>
                    @endunless
                </div>
            @endforeach
        </div>
    </section>

    {{-- Per institution, with drill-down. --}}
    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
            {{ __('By institution') }}
        </h2>

        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('Institution') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Income') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Expenditure') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Surplus') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Cash') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Ledger') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($position['rows'] as $row)
                        <tr>
                            <td class="px-4 py-2">
                                <a href="{{ route('body.institutions.show', $row->institution) }}"
                                   class="font-medium text-ink hover:underline">{{ $row->institution->name }}</a>
                                <span class="ml-2 text-xs text-ink-muted">{{ $row->institution->system }}</span>
                            </td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->income" /></td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->expenditure" /></td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->surplus" /></td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->cash_closing" /></td>
                            <td class="px-4 py-2">
                                @if ($row->balanced === null)
                                    <span class="text-xs italic text-ink-muted">—</span>
                                @elseif ($row->balanced)
                                    <span class="text-xs text-emerald-700">{{ __('balances') }}</span>
                                @else
                                    {{-- Accepted at ingest on purpose: refusing it would
                                         make this institution look silent rather than alarming. --}}
                                    <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-800
                                                ">{{ __('does not balance') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- 5. COLLECTION AND ARREARS. --}}
    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
            {{ __('Collection and arrears') }}
        </h2>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Expected fees') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $collections['expected']->formatted() }}</p>
                <x-coverage :aggregate="$collections['expected']" />
            </div>
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Collected') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $collections['collected']->formatted() }}</p>
                <x-coverage :aggregate="$collections['collected']" />
            </div>
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Receivables') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $collections['receivables']->formatted() }}</p>
                <x-coverage :aggregate="$collections['receivables']" />
            </div>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach (['0_30' => '0–30', '31_60' => '31–60', '61_90' => '61–90', '90_plus' => '90+'] as $bucket => $label)
                <div class="rounded border border-rule bg-surface p-3">
                    <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }} {{ __('days') }}</p>
                    <p class="mt-1 font-semibold tabular-nums">{{ $collections['ageing'][$bucket]->formatted() }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- 6. BUDGET VERSUS ACTUAL. --}}
    <section>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
            {{ __('Budget versus actual') }}
        </h2>

        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('Institution') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Budgeted') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Actual') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Variance') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($budget['rows'] as $row)
                        <tr>
                            <td class="px-4 py-2">{{ $row->institution->name }}</td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->budgeted" /></td>
                            <td class="px-4 py-2 text-right"><x-figure :figure="$row->actual" /></td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                @if ($row->variance_pct === null)
                                    <span class="text-xs italic text-ink-muted">—</span>
                                @else
                                    <span @class([
                                        'font-medium',
                                        'text-rose-700' => abs($row->variance_pct) > 15,
                                        'text-ink-soft' => abs($row->variance_pct) <= 15,
                                    ])>{{ $row->variance_pct > 0 ? '+' : '' }}{{ $row->variance_pct }}%</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

@endsection
