@extends('layouts.console')

@php
    $eventNames = [
        'received' => [__('Received'), 'bg-emerald-100 text-emerald-800'],
        'rejected' => [__('Refused'), 'bg-rose-100 text-rose-800'],
        'restated' => [__('Corrected'), 'bg-amber-100 text-amber-800'],
        'heartbeat' => [__('Checked in'), 'bg-surface-sunk text-ink-soft'],
        'stale' => [__('Gone quiet'), 'bg-amber-100 text-amber-800'],
        'recovered' => [__('Back in touch'), 'bg-emerald-100 text-emerald-800'],
    ];
    $toneDot = ['good' => 'bg-emerald-500', 'warn' => 'bg-amber-500', 'bad' => 'bg-rose-600', 'muted' => 'bg-rule-strong'];
@endphp

@section('title', $institution->name)
@section('subtitle', \App\Support\InstitutionType::shortLabel($institution->type).' · '.$institution->body->name)

@section('header-actions')
    <form method="GET" class="flex items-center gap-2">
        <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
        <select name="period" id="period" onchange="this.form.submit()"
                class="rounded border border-rule-strong bg-surface px-2 py-1.5 text-sm">
            @foreach ($history as $row)
                <option value="{{ $row->period }}" @selected($row->period === $period)>{{ period_text($row->period) }}</option>
            @endforeach
            @if ($history->doesntContain('period', $period))
                <option value="{{ $period }}" selected>{{ period_text($period) }}</option>
            @endif
        </select>
    </form>

    @canany(['institution.update', 'capability.declare', 'credential.view'])
        <a href="{{ route('body.manage.institutions.show', $institution) }}"
           class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
            {{ __('Manage') }}
        </a>
    @endcanany
@endsection

@section('content')

    {{-- 1. HOW IS THIS SCHOOL DOING, IN WORDS. --}}
    <section class="mb-6 rounded border border-rule bg-surface px-5 py-4">
        <ul class="space-y-2.5 text-[15px] leading-snug text-ink">
            @foreach ($headlines as $line)
                <li class="flex items-start gap-3">
                    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $toneDot[$line->tone] }}" aria-hidden="true"></span>
                    @if ($line->href)
                        <a href="{{ $line->href }}" class="underline-offset-2 hover:underline">{{ $line->text }}</a>
                    @else
                        <span>{{ $line->text }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    {{-- 2. WHERE TO GO NEXT. --}}
    <section class="mb-8 grid gap-3 sm:grid-cols-2 {{ auth()->user()->can('staff.view') ? 'xl:grid-cols-5' : 'xl:grid-cols-4' }}">
        @can('daybook.view')
            <a href="{{ route('body.institutions.daybook', ['institution' => $institution, 'period' => $period]) }}"
               class="rounded border border-rule bg-surface px-4 py-3 transition hover:bg-surface-sunk">
                <span class="font-serif text-base text-ink">{{ __('Daybook') }}</span>
                <span class="mt-0.5 block text-xs text-ink-muted">{{ __('Every movement of money in the month, line by line.') }}</span>
            </a>
        @endcan
        <a href="{{ route('body.institutions.budget', ['institution' => $institution, 'period' => $period]) }}"
           class="rounded border border-rule bg-surface px-4 py-3 transition hover:bg-surface-sunk">
            <span class="font-serif text-base text-ink">{{ __('Budget sheet') }}</span>
            <span class="mt-0.5 block text-xs text-ink-muted">{{ __('Each budget line against what has been spent so far.') }}</span>
        </a>
        @can('staff.view')
            <a href="{{ route('body.institutions.staff', ['institution' => $institution, 'period' => $period]) }}"
               class="rounded border border-rule bg-surface px-4 py-3 transition hover:bg-surface-sunk">
                <span class="font-serif text-base text-ink">{{ __('Staff list') }}</span>
                <span class="mt-0.5 block text-xs text-ink-muted">{{ __('Who is on the books, by name, department and post.') }}</span>
            </a>
        @endcan
        <a href="{{ route('body.institutions.annual', $institution) }}?period={{ $period }}"
           class="rounded border border-rule bg-surface px-4 py-3 transition hover:bg-surface-sunk">
            <span class="font-serif text-base text-ink">{{ __('The year so far') }}</span>
            <span class="mt-0.5 block text-xs text-ink-muted">{{ __('The months added up, in the shape the bursar reads.') }}</span>
        </a>
        <a href="{{ route('body.institutions.monthly', [$institution, $period]) }}"
           class="rounded border border-rule bg-surface px-4 py-3 transition hover:bg-surface-sunk">
            <span class="font-serif text-base text-ink">{{ __('Everything sent for :month', ['month' => period_text($period)]) }}</span>
            <span class="mt-0.5 block text-xs text-ink-muted">{{ __('The full monthly report, including what it does not send.') }}</span>
        </a>
    </section>

    @if ($report)
        {{-- Warnings raised at ingest. Accepted and stored, never a reason to refuse. --}}
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

        {{-- 3. THE MONTH IN FOUR FIGURES. --}}
        <div class="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['surplus_deficit', __('Income less spending'), \EdutrustPay\Contract\Capability::LEDGER],
                ['cash_closing', __('Cash at month end'), \EdutrustPay\Contract\Capability::LEDGER],
                ['collected', __('Fees collected so far'), \EdutrustPay\Contract\Capability::ENROLMENT],
                ['receivables_total', __('Fees owed'), \EdutrustPay\Contract\Capability::RECEIVABLES],
            ] as [$column, $label, $capability])
                <div class="rounded border border-rule bg-surface p-4">
                    <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }}</p>
                    <p class="mt-1 text-xl font-semibold">
                        <x-figure :figure="$report->figure($column, $capability)" />
                    </p>
                </div>
            @endforeach
        </div>
    @endif

    {{-- 4. WHAT TO ASK THIS SCHOOL ABOUT. --}}
    @if ($findingGroups->isNotEmpty())
        <section class="mb-8">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('To look into') }}</h2>
            <div class="space-y-2">
                @foreach ($findingGroups as $group)
                    @include('body.partials.finding-compact', ['group' => $group])
                @endforeach
            </div>
        </section>
    @endif

    @if ($report)
        @php $classNames = $institution->accountClassNames(); @endphp
        {{--
            5. BY ACCOUNT CLASS.

            The report stops at the OHADA account class. Schools name and use the
            classes in their own way (one may book tuition under 71, another under
            70), so no name is invented here: the daybook and budget sheet say what
            the money actually was.
        --}}
        <section class="mb-8">
            <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('By account class') }}</h2>
            <p class="mb-3 text-xs text-ink-muted">{{ __('OHADA account classes, as this school\'s books group them: 70 to 79 are income, 60 to 69 spending. The names are the school\'s own, from its chart of accounts. For what the money actually was, open the daybook or budget sheet.') }}</p>
            <div class="grid gap-6 lg:grid-cols-2">
                @foreach ([['income', __('Income')], ['expenditure', __('Spending')]] as [$section, $heading])
                    @php $sectionTotal = collect($$section)->sum(fn ($m) => (float) $m->value()); @endphp
                    <div class="overflow-hidden rounded border border-rule">
                        <table class="min-w-full divide-y divide-rule text-sm">
                            <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                                <tr><th class="px-4 py-2 font-semibold" colspan="3">{{ $heading }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-rule bg-surface">
                                @forelse ($$section as $group => $money)
                                    <tr>
                                        <td class="px-4 py-2 text-ink-soft">{{ class_label($group, $classNames[$group] ?? null) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ money_text($money->value()) }}</td>
                                        <td class="w-16 px-4 py-2 text-right text-xs tabular-nums text-ink-muted">{{ $sectionTotal > 0 ? round((float) $money->value() / $sectionTotal * 100).'%' : '' }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="px-4 py-3 text-sm italic text-ink-muted">{{ __('Nothing recorded in :month.', ['month' => period_text($period)]) }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Corrections are kept, never overwritten. Shown only when there were any. --}}
        @if ($versions->count() > 1)
            <section class="mb-8">
                <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __(':month was sent :count times', ['month' => period_text($period), 'count' => $versions->count()]) }}</h2>
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

    {{-- 6. MONTH BY MONTH. A step change is a signal; a missing month is a gap, not a zero. --}}
    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Income less spending, month by month') }}</h2>
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
                                <div @class(['w-6 rounded-t', 'bg-emerald-400' => $value >= 0, 'bg-rose-400' => $value < 0])
                                     style="height: {{ $height }}px"
                                     title="{{ period_text($point->period) }}: {{ $point->figure->formatted() }}"></div>
                            @else
                                <div class="w-6 border-b-2 border-dashed border-rule-strong"
                                     title="{{ period_text($point->period) }}: {{ $point->figure->label() }}"></div>
                            @endif
                        </div>
                        <span class="text-[10px] text-ink-muted">{{ \Carbon\Carbon::createFromFormat('!Y-m', $point->period)->translatedFormat('M y') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <p class="mt-2 text-xs text-ink-muted">{{ __('Green is more income than spending, red the reverse. A dashed gap is a month with no figures, not a month of zero.') }}</p>
    </section>

    {{-- 7. WHAT HAS ARRIVED FROM THIS SCHOOL. Refusals matter as much as arrivals. --}}
    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Recently from this school') }}</h2>
        {{-- A list, not a table: on a phone the time and badge sit above the sentence instead of squeezing it. --}}
        <ul class="divide-y divide-rule rounded border border-rule bg-surface text-sm">
            @forelse ($events as $event)
                @php [$eventLabel, $eventClass] = $eventNames[$event->type] ?? [ucfirst($event->type), 'bg-surface-sunk text-ink-soft']; @endphp
                <li class="flex flex-col gap-1 px-4 py-2 sm:flex-row sm:items-baseline sm:gap-4">
                    <div class="flex shrink-0 items-baseline gap-3 sm:w-52">
                        <span class="whitespace-nowrap text-xs text-ink-muted sm:w-24" title="{{ $event->created_at?->toDayDateTimeString() }}">{{ $event->created_at?->diffForHumans() }}</span>
                        <span class="whitespace-nowrap rounded px-1.5 py-0.5 text-[11px] font-medium {{ $eventClass }}">{{ $eventLabel }}</span>
                    </div>
                    <p class="text-ink-soft">{{ $event->summary }}</p>
                </li>
            @empty
                <li class="px-4 py-3 text-sm italic text-ink-muted">{{ __('Nothing recorded yet.') }}</li>
            @endforelse
        </ul>
    </section>

    <section>
        <x-drill-through :links="$drillThrough" :institution="$institution" :period="$period" />
    </section>

@endsection
