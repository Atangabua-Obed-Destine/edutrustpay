@extends('layouts.console')

@use('EdutrustPay\Contract\Capability')
@use('EdutrustPay\Contract\Money')

@section('title', __('Return for :period', ['period' => $period]))
@section('subtitle', $institution->name . ' · ' . $institution->body->name)

@section('header-actions')
    <form method="GET" action="{{ route('body.institutions.monthly', [$institution, $period]) }}"
          class="flex items-center gap-2"
          onsubmit="event.preventDefault(); window.location = '{{ route('body.institutions.show', $institution) }}'.replace(/\/$/, '') + '/return/' + this.period.value;">
        <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
        <select name="period" id="period" onchange="this.form.requestSubmit()"
                class="rounded border border-rule-strong bg-surface px-2 py-1.5 text-sm">
            @foreach ($history as $row)
                <option value="{{ $row->period }}" @selected($row->period === $period)>{{ $row->period }}</option>
            @endforeach
            @if ($history->doesntContain('period', $period))
                <option value="{{ $period }}" selected>{{ $period }}</option>
            @endif
        </select>
    </form>

    <a href="{{ route('body.institutions.annual', $institution) }}?period={{ $period }}"
       class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
        {{ __('Year to date') }}
    </a>
@endsection

@section('content')

    <x-breadcrumb :items="[
        __('Institutions') => route('body.institutions.index'),
        $institution->name => route('body.institutions.show', $institution),
        __('Return :period', ['period' => $period]) => null,
    ]" />

    @if (! $report)
        <div class="rounded border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ __('No report has been received for :period. This is an absence of information, not a set of zeroes.', ['period' => $period]) }}
        </div>
    @else

        {{-- ══════════════════════════════════════════════════════════
             Provenance.

             EdutrustPay is not the authority on an institution's books, but it
             IS the authority on what it was sent — so the first thing this page
             establishes is exactly what arrived, when, signed by which key, and
             whether the stored bytes still hash to what was recorded on
             receipt. Everything below is only as good as this strip.
             ══════════════════════════════════════════════════════════ --}}
        <div class="mb-6 flex flex-wrap items-center gap-x-6 gap-y-2 rounded border border-rule bg-surface-sunk px-4 py-3 text-xs text-ink-soft">
            <span>
                <span class="text-ink-muted">{{ __('Status') }}</span>
                <strong class="ml-1 {{ $report->isFinal() ? 'text-emerald-800' : 'text-amber-800' }}">
                    {{ $report->isFinal() ? __('final') : __('provisional') }}
                </strong>
            </span>
            <span><span class="text-ink-muted">{{ __('Version') }}</span> <strong class="ml-1">{{ $report->sequence }}</strong></span>
            <span><span class="text-ink-muted">{{ __('Received') }}</span> <strong class="ml-1">{{ $report->received_at?->toDayDateTimeString() }}</strong></span>
            <span><span class="text-ink-muted">{{ __('Contract') }}</span> <strong class="ml-1">{{ $report->contract_version }}</strong></span>
            <span><span class="text-ink-muted">{{ __('Key') }}</span> <strong class="ml-1 font-mono">{{ $report->key_id }}</strong></span>
            <span class="{{ $report->payloadIsIntact() ? 'text-emerald-800' : 'font-semibold text-rose-700' }}">
                {{ $report->payloadIsIntact()
                    ? __('Stored payload matches its signature hash.')
                    : __('STORED PAYLOAD NO LONGER MATCHES ITS HASH.') }}
            </span>
        </div>

        {{-- Warnings raised by the contract validator at ingest. These were
             ACCEPTED and stored, then turned into findings — never a reason to
             refuse a report. --}}
        @if ($report->warningList())
            <div class="mb-6 rounded border-l-4 border-rose-400 bg-rose-50 px-4 py-3">
                <p class="text-sm font-semibold text-rose-900">{{ __('This report disagrees with itself') }}</p>
                <ul class="mt-1 space-y-1 text-sm text-rose-800">
                    @foreach ($report->warningList() as $warning)
                        <li>· {{ $warning['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php $currency = $report->currency; @endphp

        {{-- ══════════════════════════════════════════════════════════
             Income and expenditure, by OHADA group
             ══════════════════════════════════════════════════════════ --}}
        <section class="mb-8">
            <h2 class="mb-3 font-serif text-lg text-ink">{{ __('Income and expenditure') }}</h2>

            @if (! $report->declares(Capability::LEDGER))
                <p class="rounded border border-dashed border-rule-strong bg-surface px-4 py-6 text-center text-sm italic text-ink-muted">
                    {{ __('This institution does not report a ledger yet, so there is no income and expenditure statement — not a statement of zero.') }}
                </p>
            @else
                <div class="grid gap-6 lg:grid-cols-2">
                    @foreach ([['income', __('Income'), 'expenditure'], ['expenditure', __('Expenditure'), 'income']] as [$section, $heading, $other])
                        <div class="overflow-hidden rounded border border-rule bg-surface">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-rule bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                                        <th class="px-4 py-2 font-semibold">{{ $heading }}</th>
                                        <th class="px-4 py-2 text-right font-semibold">{{ $currency }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-rule">
                                    @forelse ($$section as $group => $money)
                                        <tr>
                                            <td class="px-4 py-2">
                                                <span class="font-mono text-xs text-ink-muted">{{ $group }}</span>
                                                <span class="ml-2 text-ink-soft">{{ ohada_group_name($group) }}</span>
                                            </td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format((float) $money->value(), 0, '.', ' ') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="2" class="px-4 py-3 text-sm italic text-ink-muted">{{ __('Nothing reported in this section.') }}</td></tr>
                                    @endforelse
                                </tbody>
                                @if (count($$section))
                                    <tfoot>
                                        <tr class="border-t-2 border-rule-strong font-semibold">
                                            <td class="px-4 py-2">{{ __('Total') }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">
                                                {{ number_format((float) Money::sum(array_values($$section))->value(), 0, '.', ' ') }}
                                            </td>
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <x-stat :label="__('Surplus / deficit')" :figure="$report->figure('surplus_deficit', Capability::LEDGER)" />
                    <x-stat :label="__('Cash at month start')" :figure="$report->figure('cash_opening', Capability::LEDGER)" />
                    <x-stat :label="__('Cash movement')" :figure="$report->figure('cash_movement', Capability::LEDGER)" />
                    <x-stat :label="__('Cash at month end')" :figure="$report->figure('cash_closing', Capability::LEDGER)" />
                </div>
            @endif
        </section>

        {{-- ══════════════════════════════════════════════════════════
             Ageing. The bar exists because four numbers in a table tell you
             what the buckets are; the bar tells you that most of the debt is
             over ninety days old, which is the thing anyone actually wants
             from an ageing report.
             ══════════════════════════════════════════════════════════ --}}
        <section class="mb-8 grid gap-6 lg:grid-cols-2">
            @foreach ([
                ['receivables', __('Receivables'), Capability::RECEIVABLES, __('Owed to this institution — mostly unpaid fees.')],
                ['payables', __('Payables'), Capability::PAYABLES, __('Owed by this institution to suppliers and staff.')],
            ] as [$prefix, $heading, $capability, $blurb])
                <x-card :title="$heading" :subtitle="$blurb">
                    @if (! $report->declares($capability))
                        <p class="text-sm italic text-ink-muted">
                            {{ __('Not reported. This institution cannot produce :what yet — the console shows that rather than showing zero.', ['what' => mb_strtolower($heading)]) }}
                        </p>
                    @else
                        <p class="mb-3 font-serif text-2xl text-ink">
                            <x-figure :figure="$report->figure($prefix.'_total', $capability)" />
                            <span class="ml-1 text-xs text-ink-muted">{{ $currency }}</span>
                        </p>

                        <x-bucket-bar :buckets="[
                            ['label' => __('0–30 days'), 'value' => $report->{$prefix.'_0_30'}],
                            ['label' => __('31–60'), 'value' => $report->{$prefix.'_31_60'}],
                            ['label' => __('61–90'), 'value' => $report->{$prefix.'_61_90'}],
                            ['label' => __('over 90'), 'value' => $report->{$prefix.'_90_plus'}],
                        ]" />
                    @endif
                </x-card>
            @endforeach
        </section>

        {{-- ══════════════════════════════════════════════════════════
             Enrolment, budget, payroll, bank
             ══════════════════════════════════════════════════════════ --}}
        <section class="mb-8 grid gap-6 lg:grid-cols-2">

            <x-card :title="__('Fees and enrolment')"
                    :subtitle="__('Expected fees are the institution\'s own figure until the body pushes fee structures down.')">
                @if (! $report->declares(Capability::ENROLMENT))
                    <p class="text-sm italic text-ink-muted">{{ __('Enrolment is not reported.') }}</p>
                @else
                    <dl class="divide-y divide-rule text-sm">
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">{{ __('Students') }}</dt>
                            <dd class="tabular-nums font-medium">{{ $report->enrolment !== null ? number_format((int) $report->enrolment) : '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">{{ __('Expected fees') }}</dt>
                            <dd><x-figure :figure="$report->figure('expected_fees', Capability::ENROLMENT)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">{{ __('Collected') }}</dt>
                            <dd><x-figure :figure="$report->figure('collected', Capability::ENROLMENT)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">{{ __('Collection rate') }}</dt>
                            <dd class="tabular-nums font-medium">
                                {{ $report->collection_rate !== null ? round((float) $report->collection_rate * 100, 1).'%' : '—' }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">
                                {{ __('Received as cash') }}
                                <span class="block text-xs text-ink-muted">{{ __('Money that never reaches an account cannot be traced by any figure in this report.') }}</span>
                            </dt>
                            <dd class="tabular-nums font-medium {{ (float) $report->cash_share >= 0.8 ? 'text-rose-700' : '' }}">
                                {{ $report->cash_share !== null ? round((float) $report->cash_share * 100, 1).'%' : '—' }}
                            </dd>
                        </div>
                    </dl>
                @endif
            </x-card>

            <x-card :title="__('Budget')" :subtitle="__('Budgeted against actual, for this period.')">
                @if (! $report->declares(Capability::BUDGET))
                    <p class="text-sm italic text-ink-muted">
                        {{ __('Budget is not reported. The institution has a budget; the contract does not yet carry it in a shape this institution can honestly produce.') }}
                    </p>
                @else
                    <dl class="divide-y divide-rule text-sm">
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">{{ __('Budgeted') }}</dt>
                            <dd><x-figure :figure="$report->figure('budgeted', Capability::BUDGET)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">{{ __('Actual') }}</dt>
                            <dd><x-figure :figure="$report->figure('budget_actual', Capability::BUDGET)" /></dd>
                        </div>
                    </dl>
                @endif
            </x-card>

            <x-card :title="__('Payroll')">
                @if (! $report->declares(Capability::PAYROLL))
                    <p class="text-sm italic text-ink-muted">{{ __('Payroll is not reported.') }}</p>
                @else
                    <dl class="divide-y divide-rule text-sm">
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">{{ __('Staff cost') }}</dt>
                            <dd><x-figure :figure="$report->figure('staff_cost', Capability::PAYROLL)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">{{ __('Statutory liabilities') }}</dt>
                            <dd><x-figure :figure="$report->figure('statutory_liabilities', Capability::PAYROLL)" /></dd>
                        </div>
                    </dl>
                @endif
            </x-card>

            <x-card :title="__('Bank reconciliation')"
                    :subtitle="__('The only control that compares the books against a source the bursar does not author.')">
                @if (! $report->declares(Capability::BANK_RECON))
                    <p class="text-sm italic text-ink-muted">{{ __('Bank reconciliation is not reported.') }}</p>
                @else
                    <dl class="divide-y divide-rule text-sm">
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">{{ __('Per the statement') }}</dt>
                            <dd><x-figure :figure="$report->figure('bank_statement_closing', Capability::BANK_RECON)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="text-ink-soft">{{ __('Per the ledger') }}</dt>
                            <dd><x-figure :figure="$report->figure('bank_ledger_closing', Capability::BANK_RECON)" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between py-2">
                            <dt class="font-medium text-ink">{{ __('Divergence') }}</dt>
                            <dd class="font-semibold {{ (float) $report->bank_divergence != 0.0 ? 'text-rose-700' : 'text-emerald-800' }}">
                                <x-figure :figure="$report->figure('bank_divergence', Capability::BANK_RECON)" />
                            </dd>
                        </div>
                    </dl>
                @endif
            </x-card>
        </section>

        {{-- ══════════════════════════════════════════════════════════
             Integrity — the tamper-evidence block.
             ══════════════════════════════════════════════════════════ --}}
        <section class="mb-8">
            <x-card :title="__('Integrity')"
                    :subtitle="__('What the institution says about its own ledger discipline this month.')">
                @if (! $report->declares(Capability::INTEGRITY))
                    <p class="text-sm italic text-ink-muted">{{ __('Integrity figures are not reported.') }}</p>
                @else
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ([
                            ['journal_entry_count', __('Journal entries'), __('Postings made in the month.')],
                            ['unposted_count', __('Unposted'), __('Entered but never posted. A backlog here is where an unbalanced month hides.')],
                            ['reversals', __('Reversals'), __('Legitimate in themselves; a rising rate is the signal.')],
                            ['backdated_entries', __('Backdated'), __('Dated inside this month but entered after it closed.')],
                            ['audit_events', __('Audit events'), __('Recorded by the institution\'s own trail.')],
                        ] as [$column, $label, $hint])
                            <div class="rounded border border-rule px-3 py-2">
                                <p class="text-[11px] uppercase tracking-wider text-ink-muted">{{ $label }}</p>
                                <p class="mt-0.5 font-serif text-xl text-ink tabular-nums">
                                    {{ $report->{$column} !== null ? number_format((int) $report->{$column}) : '—' }}
                                </p>
                                <p class="mt-1 text-[11px] leading-snug text-ink-muted">{{ $hint }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 border-t border-rule pt-3">
                        <p class="text-[11px] uppercase tracking-wider text-ink-muted">{{ __('Ledger hash') }}</p>
                        <p class="mt-1 break-all font-mono text-xs text-ink-soft">{{ $report->ledger_hash ?: __('not reported') }}</p>
                        <p class="mt-1 text-[11px] text-ink-muted">
                            {{ __('The institution\'s own fingerprint of its posted ledger for this month. Two months that should be identical and are not will differ here.') }}
                        </p>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-[11px] uppercase tracking-wider text-ink-muted">{{ __('Total debits') }}</p>
                            <p class="font-serif text-lg text-ink"><x-figure :figure="$report->figure('total_debits', Capability::LEDGER)" /></p>
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-wider text-ink-muted">{{ __('Total credits') }}</p>
                            <p class="font-serif text-lg text-ink"><x-figure :figure="$report->figure('total_credits', Capability::LEDGER)" /></p>
                        </div>
                    </div>
                @endif
            </x-card>
        </section>

        {{-- Findings raised against this month. --}}
        @if ($findings->isNotEmpty())
            <section class="mb-8">
                <h2 class="mb-3 font-serif text-lg text-ink">{{ __('Findings for this month') }}</h2>
                <div class="space-y-2">
                    @foreach ($findings as $finding)
                        @include('body.partials.finding-row', ['finding' => $finding])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Where this console stops and the institution's own books begin. --}}
        <section class="mb-8">
            <x-drill-through :links="$drillThrough" :institution="$institution" :period="$period" />
        </section>

        {{-- Restatements: superseded versions are RETAINED and stay visible.
             That is the difference between restating and overwriting. --}}
        @if ($versions->count() > 1)
            <section>
                <x-card :title="__('Versions of this month')"
                        :subtitle="__('A restatement supersedes; it never overwrites. Every version this institution filed is still here.')">
                    <table class="min-w-full divide-y divide-rule text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-ink-muted">
                            <tr>
                                <th class="py-2 font-semibold">{{ __('Version') }}</th>
                                <th class="py-2 font-semibold">{{ __('Status') }}</th>
                                <th class="py-2 font-semibold">{{ __('Received') }}</th>
                                <th class="py-2 text-right font-semibold">{{ __('Surplus / deficit') }}</th>
                                <th class="py-2 text-right font-semibold"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rule">
                            @foreach ($versions as $version)
                                <tr class="{{ $version->is_current ? '' : 'text-ink-muted' }}">
                                    <td class="py-2">{{ $version->sequence }}</td>
                                    <td class="py-2">{{ $version->status }}</td>
                                    <td class="py-2">{{ $version->received_at?->toDayDateTimeString() }}</td>
                                    <td class="py-2 text-right tabular-nums">
                                        {{ $version->surplus_deficit !== null ? number_format((float) $version->surplus_deficit, 0, '.', ' ') : '—' }}
                                    </td>
                                    <td class="py-2 text-right text-xs">
                                        {{ $version->is_current ? __('current') : __('superseded') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-card>
            </section>
        @endif
    @endif

@endsection
