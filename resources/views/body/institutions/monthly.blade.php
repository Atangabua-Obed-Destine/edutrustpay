@extends('layouts.console')

@use('EdutrustPay\Contract\Capability')

@php
    $month = period_text($period);
    $classNames = $institution->accountClassNames();
    // The same words for "this school doesn't send that" in every card below.
    $notSent = fn (string $what) => __(':school doesn\'t send :what. It is missing, not zero.', ['school' => $institution->name, 'what' => $what]);
@endphp

@section('title', __('Everything sent for :month', ['month' => $month]))
@section('subtitle', $institution->name . ' · ' . $institution->body->name)

@section('header-actions')
    <form method="GET" action="{{ route('body.institutions.monthly', [$institution, $period]) }}"
          class="flex items-center gap-2"
          onsubmit="event.preventDefault(); window.location = '{{ route('body.institutions.show', $institution) }}'.replace(/\/$/, '') + '/return/' + this.period.value;">
        <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
        <select name="period" id="period" onchange="this.form.requestSubmit()"
                class="rounded border border-rule-strong bg-surface px-2 py-1.5 text-sm">
            @foreach ($history as $row)
                <option value="{{ $row->period }}" @selected($row->period === $period)>{{ period_text($row->period) }}</option>
            @endforeach
            @if ($history->doesntContain('period', $period))
                <option value="{{ $period }}" selected>{{ $month }}</option>
            @endif
        </select>
    </form>

    <a href="{{ route('body.institutions.annual', $institution) }}?period={{ $period }}"
       class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
        {{ __('The year so far') }}
    </a>
@endsection

@section('content')

    <x-breadcrumb :items="[
        __('Institutions') => route('body.institutions.index'),
        $institution->name => route('body.institutions.show', ['institution' => $institution, 'period' => $period]),
        $month => null,
    ]" />

    @if (! $report)
        <div class="rounded border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            @if ($period >= now()->format('Y-m'))
                {{ __(':month has not finished, so no report is due yet.', ['month' => $month]) }}
            @else
                {{ __('No report has arrived for :month. That is missing information, not a month of zeroes.', ['month' => $month]) }}
            @endif
        </div>
    @else

        {{-- ══════════════════════════════════════════════════════════
             What arrived, and whether it is still exactly what arrived.

             The console is not the authority on the school's books, but it is
             the authority on what it was sent. The technical proof (contract,
             key, hash) is kept, folded away for whoever needs it.
             ══════════════════════════════════════════════════════════ --}}
        <section class="mb-6 rounded border border-rule bg-surface px-5 py-4 text-[15px] leading-snug text-ink">
            <p>
                {{ __('Sent on :date.', ['date' => $report->generated_at->toDayDateTimeString()]) }}
                @if ($report->isFinal())
                    {{ __('The month is closed, so these figures should not change.') }}
                @else
                    {{ __('The month is still open, so these figures may still change.') }}
                @endif
                @if ($versions->count() > 1)
                    {{ __('This is version :n; the earlier ones are kept at the foot of the page.', ['n' => $report->sequence]) }}
                @endif
            </p>
            <p class="mt-1.5 text-sm {{ $report->payloadIsIntact() ? 'text-ink-muted' : 'font-semibold text-rose-700' }}">
                {{ $report->payloadIsIntact()
                    ? __('It was signed by the school\'s system and has not changed since it arrived.')
                    : __('Warning: what is stored no longer matches what arrived. Do not rely on these figures until this is explained.') }}
            </p>
            <details class="mt-2 text-xs text-ink-muted">
                <summary class="cursor-pointer select-none">{{ __('Technical details') }}</summary>
                <dl class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                    <div><dt class="inline">{{ __('Received') }}:</dt> <dd class="inline">{{ $report->received_at?->toDayDateTimeString() }}</dd></div>
                    <div><dt class="inline">{{ __('Version') }}:</dt> <dd class="inline">{{ $report->sequence }}</dd></div>
                    <div><dt class="inline">{{ __('Report format') }}:</dt> <dd class="inline">{{ $report->contract_version }}</dd></div>
                    <div><dt class="inline">{{ __('Signing key') }}:</dt> <dd class="inline font-mono">{{ $report->key_id }}</dd></div>
                    <div class="sm:col-span-2 break-all"><dt class="inline">{{ __('Fingerprint of the school\'s books') }}:</dt> <dd class="inline font-mono">{{ $report->ledger_hash ?: '—' }}</dd></div>
                </dl>
            </details>
        </section>

        {{-- Warnings raised when the report arrived. Accepted and stored, never a reason to refuse. --}}
        @if ($report->warningList())
            <div class="mb-6 rounded border-l-4 border-rose-400 bg-rose-50 px-4 py-3">
                <p class="text-sm font-semibold text-rose-900">{{ __('Points to check in this report') }}</p>
                <ul class="mt-1 space-y-1 text-sm text-rose-800">
                    @foreach ($report->warningList() as $warning)
                        <li>· {{ $warning['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════
             Money in and out, by account class.
             ══════════════════════════════════════════════════════════ --}}
        <section class="mb-8">
            <h2 class="mb-1 font-serif text-lg text-ink">{{ __('Money in and out') }}</h2>

            @if (! $report->declares(Capability::LEDGER))
                <p class="rounded border border-dashed border-rule-strong bg-surface px-4 py-6 text-center text-sm italic text-ink-muted">
                    {{ $notSent(__('its income and spending')) }}
                </p>
            @else
                <p class="mb-3 text-xs text-ink-muted">{{ __('By OHADA account class, as this school\'s books group them: 70 to 79 are income, 60 to 69 spending. The names are the school\'s own, from its chart of accounts. For what the money actually was, open the daybook.') }}</p>

                <div class="grid gap-6 lg:grid-cols-2">
                    @foreach ([['income', __('Income')], ['expenditure', __('Spending')]] as [$section, $heading])
                        @php $sectionTotal = collect($$section)->sum(fn ($m) => (float) $m->value()); @endphp
                        <div class="overflow-hidden rounded border border-rule bg-surface">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-rule bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                                        <th class="px-4 py-2 font-semibold" colspan="2">{{ $heading }}</th>
                                        <th class="px-4 py-2 text-right font-semibold">{{ $report->currency }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-rule">
                                    @forelse ($$section as $group => $money)
                                        <tr>
                                            <td class="px-4 py-2 text-ink-soft">{{ class_label($group, $classNames[$group] ?? null) }}</td>
                                            <td class="w-16 px-2 py-2 text-right text-xs tabular-nums text-ink-muted">{{ $sectionTotal > 0 ? round((float) $money->value() / $sectionTotal * 100).'%' : '' }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ money_text($money->value()) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="px-4 py-3 text-sm italic text-ink-muted">{{ __('None recorded in :month.', ['month' => $month]) }}</td></tr>
                                    @endforelse
                                </tbody>
                                @if (count($$section))
                                    <tfoot>
                                        <tr class="border-t-2 border-rule-strong font-semibold">
                                            <td class="px-4 py-2" colspan="2">{{ __('Total') }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ money_text($sectionTotal) }}</td>
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <x-stat :label="__('Income less spending')" :figure="$report->figure('surplus_deficit', Capability::LEDGER)" />
                    <x-stat :label="__('Cash at the start of the month')" :figure="$report->figure('cash_opening', Capability::LEDGER)" />
                    <x-stat :label="__('Change in cash')" :figure="$report->figure('cash_movement', Capability::LEDGER)" />
                    <x-stat :label="__('Cash at month end')" :figure="$report->figure('cash_closing', Capability::LEDGER)" />
                </div>
            @endif
        </section>

        {{-- ══════════════════════════════════════════════════════════
             Who owes whom, by how long. The bar says at a glance whether
             most of the debt is old, which is what anyone wants to know.
             ══════════════════════════════════════════════════════════ --}}
        <section class="mb-8 grid gap-6 lg:grid-cols-2">
            @foreach ([
                ['receivables', __('Owed to the school'), Capability::RECEIVABLES, __('Mostly unpaid fees, by how long they have been owed.'), __('what it is owed')],
                ['payables', __('Owed by the school'), Capability::PAYABLES, __('To suppliers and staff, by how long it has been owing.'), __('what it owes')],
            ] as [$prefix, $heading, $capability, $blurb, $what])
                <x-card :title="$heading" :subtitle="$blurb">
                    @if (! $report->declares($capability))
                        <p class="text-sm italic text-ink-muted">{{ $notSent($what) }}</p>
                    @else
                        <p class="mb-3 font-serif text-2xl text-ink">
                            <x-figure :figure="$report->figure($prefix.'_total', $capability)" />
                            <span class="ml-1 text-xs text-ink-muted">{{ $report->currency }}</span>
                        </p>

                        <x-bucket-bar :buckets="[
                            ['label' => __('up to 30 days'), 'value' => $report->{$prefix.'_0_30'}],
                            ['label' => __('31 to 60'), 'value' => $report->{$prefix.'_31_60'}],
                            ['label' => __('61 to 90'), 'value' => $report->{$prefix.'_61_90'}],
                            ['label' => __('over 90 days'), 'value' => $report->{$prefix.'_90_plus'}],
                        ]" />
                    @endif
                </x-card>
            @endforeach
        </section>

        {{-- ══════════════════════════════════════════════════════════
             Fees, budget, staff pay, bank
             ══════════════════════════════════════════════════════════ --}}
        <section class="mb-8 grid gap-6 lg:grid-cols-2">

            <x-card :title="__('Students and fees collected')" :subtitle="__('The school\'s own figures.')">
                @if (! $report->declares(Capability::ENROLMENT))
                    <p class="text-sm italic text-ink-muted">{{ $notSent(__('its student numbers or fees billed against fees collected')) }}</p>
                @else
                    <dl class="divide-y divide-rule text-sm">
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">{{ __('Students') }}</dt>
                            <dd class="tabular-nums font-medium">{{ $report->enrolment !== null ? number_format((int) $report->enrolment) : '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">{{ __('Fees billed so far') }}</dt>
                            <dd><x-figure :figure="$report->figure('expected_fees', Capability::ENROLMENT)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">{{ __('Collected so far') }}</dt>
                            <dd><x-figure :figure="$report->figure('collected', Capability::ENROLMENT)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">{{ __('Collected, as a share of fees billed') }}</dt>
                            <dd class="tabular-nums font-medium">
                                {{ $report->collection_rate !== null ? round((float) $report->collection_rate * 100, 1).'%' : '—' }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">
                                {{ __('Taken as physical cash') }}
                                <span class="block text-xs text-ink-muted">{{ __('Cash that never reaches a bank account is the hardest money to trace.') }}</span>
                            </dt>
                            <dd class="tabular-nums font-medium {{ (float) $report->cash_share >= 0.8 ? 'text-rose-700' : '' }}">
                                {{ $report->cash_share !== null ? round((float) $report->cash_share * 100, 1).'%' : '—' }}
                            </dd>
                        </div>
                    </dl>
                @endif
            </x-card>

            <x-card :title="__('Budget')">
                @if ($report->declares(Capability::BUDGET))
                    <dl class="mb-3 divide-y divide-rule text-sm">
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">{{ __('Budgeted') }}</dt>
                            <dd><x-figure :figure="$report->figure('budgeted', Capability::BUDGET)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">{{ __('Spent') }}</dt>
                            <dd><x-figure :figure="$report->figure('budget_actual', Capability::BUDGET)" /></dd>
                        </div>
                    </dl>
                @endif
                <p class="text-sm text-ink-soft">
                    {{ __('Each school sends its budget lines separately, as a budget sheet: every line\'s budget against what has been spent so far.') }}
                </p>
                <a href="{{ route('body.institutions.budget', ['institution' => $institution, 'period' => $period]) }}"
                   class="mt-2 inline-block text-sm font-medium text-accent hover:underline">{{ __('Open the budget sheet for :month', ['month' => $month]) }} →</a>
            </x-card>

            <x-card :title="__('Staff pay')">
                @if (! $report->declares(Capability::PAYROLL))
                    <p class="text-sm italic text-ink-muted">{{ $notSent(__('its payroll')) }}</p>
                @else
                    <dl class="divide-y divide-rule text-sm">
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">{{ __('Paid through payroll') }}</dt>
                            <dd><x-figure :figure="$report->figure('staff_cost', Capability::PAYROLL)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">{{ __('Owed for tax and social insurance') }}</dt>
                            <dd><x-figure :figure="$report->figure('statutory_liabilities', Capability::PAYROLL)" /></dd>
                        </div>
                    </dl>
                    @if ($report->staff_cost !== null && (float) $report->staff_cost == 0.0)
                        <p class="mt-2 text-xs text-ink-muted">{{ __('Nothing went through payroll this month. Salaries may have been recorded as ordinary spending instead; the daybook will show.') }}</p>
                    @endif
                @endif
            </x-card>

            <x-card :title="__('Bank statement check')"
                    :subtitle="__('The one check against a record the bursar does not write.')">
                @if (! $report->declares(Capability::BANK_RECON))
                    <p class="text-sm italic text-ink-muted">{{ $notSent(__('a check of its books against the bank statement')) }}</p>
                @else
                    <dl class="divide-y divide-rule text-sm">
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">{{ __('The bank statement says') }}</dt>
                            <dd><x-figure :figure="$report->figure('bank_statement_closing', Capability::BANK_RECON)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-ink-soft">{{ __('The school\'s books say') }}</dt>
                            <dd><x-figure :figure="$report->figure('bank_ledger_closing', Capability::BANK_RECON)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="font-medium text-ink">{{ __('Difference') }}</dt>
                            <dd class="font-semibold {{ (float) $report->bank_divergence != 0.0 ? 'text-rose-700' : 'text-emerald-800' }}">
                                <x-figure :figure="$report->figure('bank_divergence', Capability::BANK_RECON)" />
                            </dd>
                        </div>
                    </dl>
                @endif
            </x-card>
        </section>

        {{-- ══════════════════════════════════════════════════════════
             What is actually being charged, class by class. No student
             is named; this is the schedule as the school applies it.
             ══════════════════════════════════════════════════════════ --}}
        @php $feesCharged = $report->declares(Capability::FEES_CHARGED) ? ($report->decodedPayload()['operational']['fees_charged'] ?? null) : null; @endphp
        <section class="mb-8">
            <h2 class="mb-1 font-serif text-lg text-ink">{{ __('Fees charged') }}</h2>
            @if (! $feesCharged)
                <p class="rounded border border-dashed border-rule-strong bg-surface px-4 py-6 text-center text-sm italic text-ink-muted">
                    {{ $notSent(__('the fees it charges')) }}
                </p>
            @else
                <p class="mb-3 text-xs text-ink-muted">
                    {{ __('What the school is charging for the :session year, per student, and how many students each fee was charged to. These are what your fee rules are checked against.', ['session' => $feesCharged['session']]) }}
                </p>
                <div class="overflow-x-auto rounded border border-rule bg-surface">
                    <table class="min-w-full divide-y divide-rule text-sm">
                        <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                            <tr>
                                <th class="px-4 py-2 font-semibold">{{ __('Class') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Fee') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Per student') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Students') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rule">
                            @foreach (collect($feesCharged['rows'])->sortBy(['level', 'category']) as $fee)
                                <tr>
                                    <td class="px-4 py-2 text-ink-soft">{{ $fee['level'] }}</td>
                                    <td class="px-4 py-2">{{ $fee['category'] }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money_text($fee['amount']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-ink-muted">{{ number_format((int) $fee['students']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- ══════════════════════════════════════════════════════════
             How the books were kept. The school's own account of its
             bookkeeping this month: counts, not amounts.
             ══════════════════════════════════════════════════════════ --}}
        <section class="mb-8">
            <x-card :title="__('How the books were kept')"
                    :subtitle="__('Counts from the school\'s own system for :month. None of these is wrong in itself; a sudden change is worth asking about.', ['month' => $month])">
                @if (! $report->declares(Capability::INTEGRITY))
                    <p class="text-sm italic text-ink-muted">{{ $notSent(__('these counts')) }}</p>
                @else
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ([
                            ['journal_entry_count', __('Entries made'), __('Entries recorded in the month.')],
                            ['unposted_count', __('Entered but never posted'), __('Started and left unfinished. A pile of these can hide a month that does not add up.')],
                            ['reversals', __('Entries reversed'), __('Normal for correcting mistakes; a rising number is worth asking about.')],
                            ['backdated_entries', __('Entered late'), __('Dated in this month but typed in after it ended.')],
                            ['audit_events', __('Actions in the audit trail'), __('Everything the school\'s own system logged.')],
                        ] as [$column, $label, $hint])
                            <div class="rounded border border-rule px-3 py-2">
                                <p class="text-xs text-ink-muted">{{ $label }}</p>
                                <p class="mt-0.5 font-serif text-xl text-ink tabular-nums">
                                    {{ $report->{$column} !== null ? number_format((int) $report->{$column}) : '—' }}
                                </p>
                                <p class="mt-1 text-[11px] leading-snug text-ink-muted">{{ $hint }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($report->declares(Capability::LEDGER) && $report->total_debits !== null)
                    @php $offBy = round((float) $report->total_debits - (float) $report->total_credits, 2); @endphp
                    <div class="mt-4 border-t border-rule pt-3 text-sm">
                        <p class="text-ink-soft">
                            {{ __('Every entry has two sides, and the two sides should add up to the same. This month: :debits on one side, :credits on the other.', [
                                'debits' => money_text($report->total_debits),
                                'credits' => money_text($report->total_credits),
                            ]) }}
                            @if ($offBy == 0.0)
                                <span class="font-medium text-emerald-800">{{ __('They balance.') }}</span>
                            @else
                                <span class="font-semibold text-rose-700">{{ __('They are out by :amount.', ['amount' => money_text(abs($offBy))]) }}</span>
                            @endif
                        </p>
                    </div>
                @endif
            </x-card>
        </section>

        @if ($findings->isNotEmpty())
            <section class="mb-8">
                <h2 class="mb-3 font-serif text-lg text-ink">{{ __('To look into for :month', ['month' => $month]) }}</h2>
                <div class="space-y-2">
                    @foreach ($findings as $finding)
                        @include('body.partials.finding-row', ['finding' => $finding])
                    @endforeach
                </div>
            </section>
        @endif

        <section class="mb-8">
            <x-drill-through :links="$drillThrough" :institution="$institution" :period="$period" />
        </section>

        {{-- Corrections are kept, never overwritten. --}}
        @if ($versions->count() > 1)
            <section>
                <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __(':month was sent :count times', ['month' => $month, 'count' => $versions->count()]) }}</h2>
                <p class="mb-3 text-xs text-ink-muted">{{ __('Each time the school\'s figures for a month change, it sends the month again. Every version is kept, so a change can always be seen.') }}</p>
                <div class="overflow-x-auto rounded border border-rule">
                    <table class="min-w-full divide-y divide-rule text-sm">
                        <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                            <tr>
                                <th class="px-4 py-2 font-semibold">{{ __('Version') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Sent') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('The month was') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ __('Income less spending') }}</th>
                                <th class="px-4 py-2 font-semibold"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rule bg-surface">
                            @foreach ($versions as $version)
                                <tr>
                                    <td class="px-4 py-2 tabular-nums">{{ $version->sequence }}</td>
                                    <td class="px-4 py-2 text-ink-muted">{{ $version->generated_at->toDayDateTimeString() }}</td>
                                    <td class="px-4 py-2 text-ink-soft">{{ $version->status === 'final' ? __('closed') : __('still open') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $version->surplus_deficit !== null ? money_text($version->surplus_deficit) : '—' }}</td>
                                    <td class="px-4 py-2 text-xs">
                                        @if ($version->is_current)
                                            <span class="font-medium text-emerald-700">{{ __('in use') }}</span>
                                        @else
                                            <span class="text-ink-muted">{{ __('replaced, kept') }}</span>
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

@endsection
