@extends('layouts.console')

@section('title', $institution->name)
@section('subtitle', __(':system · :type · :body', [
    'system' => $institution->system,
    'type' => str_replace('_', ' ', $institution->type),
    'body' => $institution->body->name,
]))

@section('header-actions')
    <form method="GET" class="flex items-center gap-2">
        <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
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

    @canany(['institution.update', 'capability.declare', 'credential.view'])
        <a href="{{ route('body.manage.institutions.show', $institution) }}"
           class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
            {{ __('Manage') }}
        </a>
    @endcanany

    {{-- Two depths below this page, and they answer different questions: what
         exactly arrived for one month, and how the year stands so far. --}}
    <a href="{{ route('body.institutions.monthly', [$institution, $period]) }}"
       class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
        {{ __('Full return') }}
    </a>

    <a href="{{ route('body.institutions.annual', $institution) }}?period={{ $period }}"
       class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
        {{ __('Year to date') }}
    </a>
@endsection

@section('content')

    @if (! $report)
        <div class="mb-6 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800
                   ">
            {{ __('No report has been received for :period. This is an absence of information, not a set of zeroes.', ['period' => $period]) }}
        </div>
    @else

        {{-- Warnings raised by the contract validator at ingest. These were
             ACCEPTED and stored, then turned into findings — never a reason to
             refuse a report. --}}
        @if ($report->warningList())
            <div class="mb-6 rounded border border-rose-200 bg-rose-50 px-4 py-3">
                <p class="text-sm font-semibold text-rose-900">{{ __('Points to check in this report') }}</p>
                <ul class="mt-1 space-y-1 text-sm text-rose-800">
                    @foreach ($report->warningList() as $warning)
                        <li>· {{ $warning['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['surplus_deficit', __('Surplus / deficit'), \EdutrustPay\Contract\Capability::LEDGER],
                ['cash_closing', __('Cash at month end'), \EdutrustPay\Contract\Capability::LEDGER],
                ['receivables_total', __('Receivables'), \EdutrustPay\Contract\Capability::RECEIVABLES],
                ['budgeted', __('Budgeted'), \EdutrustPay\Contract\Capability::BUDGET],
            ] as [$column, $label, $capability])
                <div class="rounded border border-rule bg-surface p-4">
                    <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }}</p>
                    <p class="mt-1 text-xl font-semibold">
                        <x-figure :figure="$report->figure($column, $capability)" />
                    </p>
                </div>
            @endforeach
        </div>

        {{-- Drill-down stops at the OHADA group. Below that, the console links
             out: EdutrustPay does not hold the rows (brief §13). --}}
        <section class="mb-8 grid gap-6 lg:grid-cols-2">
            @foreach ([['income', __('Income by OHADA group')], ['expenditure', __('Expenditure by OHADA group')]] as [$section, $heading])
                <div>
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ $heading }}</h2>
                    <div class="overflow-hidden rounded border border-rule">
                        <table class="min-w-full divide-y divide-rule text-sm">
                            <tbody class="divide-y divide-rule bg-surface">
                                @forelse ($$section as $group => $money)
                                    <tr>
                                        <td class="px-4 py-2 font-mono text-xs text-ink-muted">{{ $group }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format((float) $money->value(), 0, '.', ' ') }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="px-4 py-3 text-sm italic text-ink-muted">{{ __('Nothing reported in this section.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </section>

        {{-- Restatements: superseded versions are RETAINED and stay visible.
             That is the difference between restating and overwriting. --}}
        @if ($versions->count() > 1)
            <section class="mb-8">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
                    {{ __('Versions of :period', ['period' => $period]) }}
                </h2>
                <div class="overflow-hidden rounded border border-amber-200">
                    <table class="min-w-full divide-y divide-amber-200 text-sm">
                        <thead class="bg-amber-50 text-left text-xs uppercase tracking-wide text-amber-800">
                            <tr>
                                <th class="px-4 py-2 font-semibold">{{ __('Sequence') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Status') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Generated') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Surplus') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Standing') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-amber-100 bg-surface">
                            @foreach ($versions as $version)
                                <tr>
                                    <td class="px-4 py-2 tabular-nums">{{ $version->sequence }}</td>
                                    <td class="px-4 py-2">{{ $version->status }}</td>
                                    <td class="px-4 py-2 text-ink-muted">{{ $version->generated_at->toDayDateTimeString() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $version->surplus_deficit ?? '—' }}</td>
                                    <td class="px-4 py-2">
                                        @if ($version->is_current)
                                            <span class="text-xs font-medium text-emerald-700">{{ __('current') }}</span>
                                        @else
                                            <span class="text-xs text-ink-muted">{{ __('superseded, retained') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif

    {{-- Trend: a step change is a signal. --}}
    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
            {{ __('Surplus trend') }}
        </h2>
        <div class="overflow-x-auto rounded border border-rule bg-surface p-4">
            <div class="flex min-w-max items-end gap-1">
                @php
                    $values = collect($trend)->filter(fn ($t) => $t->figure->hasValue())
                        ->map(fn ($t) => abs((float) $t->figure->money()->value()));
                    $peak = $values->max() ?: 1;
                @endphp
                @foreach ($trend as $point)
                    @php
                        $has = $point->figure->hasValue();
                        $value = $has ? (float) $point->figure->money()->value() : 0;
                        $height = $has ? max(3, (int) round(abs($value) / $peak * 80)) : 0;
                    @endphp
                    <div class="flex w-12 flex-col items-center gap-1">
                        <div class="flex h-20 w-full items-end justify-center">
                            @if ($has)
                                <div @class([
                                        'w-6 rounded-t',
                                        'bg-emerald-400' => $value >= 0,
                                        'bg-rose-400' => $value < 0,
                                     ])
                                     style="height: {{ $height }}px"
                                     title="{{ $point->period }}: {{ $point->figure->formatted() }}"></div>
                            @else
                                {{-- A month with no report is a GAP, not a zero-height bar
                                     sitting on the axis. A reader scanning this must not
                                     mistake silence for a flat month. --}}
                                <div class="w-6 border-b-2 border-dashed border-rule-strong"
                                     title="{{ $point->period }}: {{ $point->figure->label() }}"></div>
                            @endif
                        </div>
                        <span class="text-[10px] text-ink-muted">{{ substr($point->period, 2) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- The sync log: rejected pushes are as informative as accepted ones. --}}
    <section>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
            {{ __('Recent activity') }}
        </h2>
        <div class="overflow-hidden rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <tbody class="divide-y divide-rule bg-surface">
                    @forelse ($events as $event)
                        <tr>
                            <td class="px-4 py-2 text-xs text-ink-muted">{{ $event->created_at?->diffForHumans() }}</td>
                            <td class="px-4 py-2">
                                <span @class([
                                    'rounded px-1.5 py-0.5 text-[11px] font-medium',
                                    'bg-emerald-100 text-emerald-800' => $event->type === 'received',
                                    'bg-rose-100 text-rose-800' => $event->type === 'rejected',
                                    'bg-amber-100 text-amber-800' => $event->type === 'restated',
                                    'bg-surface-sunk text-ink-soft' => in_array($event->type, ['heartbeat', 'stale', 'recovered']),
                                ])>{{ $event->type }}</span>
                            </td>
                            <td class="px-4 py-2 text-ink-soft">{{ $event->summary }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-3 text-sm italic text-ink-muted">{{ __('Nothing recorded yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-8">
        <x-drill-through :links="$drillThrough" :institution="$institution" :period="$period" />
    </section>

@endsection
