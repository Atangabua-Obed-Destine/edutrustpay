@extends('layouts.console')

@section('title', __('Overview'))
@section('subtitle', period_text($period))

@section('header-actions')
    <x-print-button />
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
        0. WHO THIS IS FOR, AND WHERE TO START.

        Someone signing in for the first time met a wall of tables with no word
        on what the console is for. The welcome says it in two sentences, and
        the guide below it stays open until the person hides it themselves.
    --}}
    @php
        $user = auth()->user();
        $roleName = $user->roles->first()?->display_name;
        $canAct = ! \App\Support\BodyContext::isImpersonating();
        $showGuide = $user->guide_hidden_at === null;
    @endphp

    <section class="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
        <div class="min-w-0">
            <p class="font-serif text-xl leading-snug text-ink">{{ __('Welcome, :name', ['name' => $user->name]) }}</p>
            <p class="mt-1 max-w-3xl text-sm text-ink-soft">
                @if ($institutions->isEmpty())
                    {{ __('This console will show you how each of your schools keeps its money and whether it follows your rules, once the first one is connected.') }}
                @else
                    {{ trans_choice(
                        '{1}Here is how your school stands for :month: whether its report arrived, whether it follows your rules, and what is worth asking about.|[2,*]Here is how your :count :many stand for :month: whether their reports arrived, whether they follow your rules, and what is worth asking about.',
                        $institutions->count(),
                        ['count' => $institutions->count(), 'many' => $overview->many, 'month' => period_text($period)]
                    ) }}
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
            @if ($roleName)
                <span class="text-ink-muted">{{ __('Signed in as :role', ['role' => $roleName]) }}</span>
            @endif
            <a href="{{ route('body.guide') }}" class="font-medium text-ink underline underline-offset-2 hover:text-ink-soft">{{ __('How this console works') }}</a>
        </div>
    </section>

    @if ($institutions->isEmpty())
        <div class="mb-8 rounded border border-dashed border-rule-strong bg-surface p-8 text-center text-sm text-ink-muted">
            {{ __('No schools are connected yet. Once one is, this page shows how it is doing.') }}
        </div>
    @else
        {{--
            1. THE KEY FIGURES.

            Reporting comes first on purpose: knowing who has gone quiet is worth
            more than any total (brief section 10). A state carries a symbol and
            words as well as its colour; an amount is plain ink, with what it
            leaves out said beside it.
        --}}
        @php
            $tileMark = [
                'good' => ['✓', 'bg-emerald-100 text-emerald-800'],
                'warn' => ['!', 'bg-amber-100 text-amber-800'],
                'bad' => ['✕', 'bg-rose-100 text-rose-800'],
                'muted' => ['–', 'bg-surface-sunk text-ink-muted'],
            ];
        @endphp
        <section aria-label="{{ __('Key figures') }}" class="mb-6 grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-3">
            @foreach ($overview->tiles as $tile)
                <a href="{{ $tile->href }}" class="group flex min-w-0 flex-col rounded border border-rule bg-surface px-3 py-3 transition-colors hover:border-rule-strong sm:px-4">
                    <span class="flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-ink-muted">{{ $tile->label }}</span>
                        @if ($tile->tone)
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold {{ $tileMark[$tile->tone][1] }}" aria-hidden="true">{{ $tileMark[$tile->tone][0] }}</span>
                        @endif
                    </span>
                    <span class="mt-1 text-xl font-semibold tabular-nums leading-tight text-ink sm:text-2xl">
                        @if ($tile->value === null)
                            <span class="text-base font-normal italic text-ink-muted">{{ __('not sent') }}</span>
                        @else
                            {{ $tile->value }}@if ($tile->unit)<span class="ml-1 text-sm font-normal text-ink-muted">{{ $tile->unit }}</span>@endif
                        @endif
                    </span>
                    @if ($tile->caveat)
                        <span class="mt-1 text-xs font-medium text-amber-800">{{ $tile->caveat }}</span>
                    @endif
                    @if ($tile->hint)
                        <span class="mt-1 text-xs leading-snug text-ink-soft group-hover:underline">{{ $tile->hint }}</span>
                    @endif
                </a>
            @endforeach
        </section>

        {{-- 2. WHERE TO START, until the person hides it. --}}
        @if ($showGuide)
            <section class="mb-8 rounded border border-rule bg-surface px-5 py-4" aria-labelledby="start-here">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="start-here" class="font-serif text-lg text-ink">{{ __('Start here') }}</h2>
                    <span class="text-xs text-ink-muted">{{ __('About 15 minutes, once a month') }}</span>
                </div>
                <p class="mt-1 max-w-3xl text-sm text-ink-soft">
                    {{ __('Each :one\'s own accounting system sends its figures here by itself every month. Nobody types them in, and nothing you do here changes a :one\'s books. Your job is to notice what needs asking about. Go through these four steps:', ['one' => $overview->one]) }}
                </p>

                <ol class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    @php
                        $steps = [
                            [__('Did every report arrive?'), __('A :one that sends nothing tells you nothing, so call it first.', ['one' => $overview->one]), '#schools', __('Your :many, below', ['many' => $overview->many]), true],
                            [__('Are your rules followed?'), __('See which fee or rule is out of line, and by how much.'), route('body.compliance.index'), __('Compliance'), $user->can('compliance.view')],
                            [__('What is worth asking about?'), $user->can('finding.resolve')
                                ? __('Questions the system cannot explain. They are not proof of anything. Answer each one once you know what happened.')
                                : __('Questions the system cannot explain. They are not proof of anything. You will see who answered each one and what they said.'),
                                $user->can('finding.view') ? route('body.findings.index') : '#look-into', __('To look into'), true],
                            $user->can('consolidation.view')
                                ? [__('How is the money?'), __('Open a :one for its month, its year so far and its daybook, or see all of them added up.', ['one' => $overview->one]), route('body.consolidation', ['period' => $period]), __('All schools together'), true]
                                : [__('How is the money?'), __('Open a :one for its month and its year so far.', ['one' => $overview->one]), '#money', __('The money, below'), true],
                        ];
                        $steps = array_values(array_filter($steps, fn ($step) => $step[4]));
                    @endphp
                    @foreach ($steps as $i => [$title, $text, $href, $linkText])
                        <li class="flex gap-3 rounded border border-rule bg-surface-sunk/50 p-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-semibold text-white">{{ $i + 1 }}</span>
                            <span class="min-w-0 text-sm">
                                <span class="block font-medium text-ink">{{ $title }}</span>
                                <span class="mt-0.5 block text-ink-soft">{{ $text }}</span>
                                <a href="{{ $href }}" class="mt-1 inline-block font-medium text-ink underline underline-offset-2 hover:text-ink-soft">{{ $linkText }} →</a>
                            </span>
                        </li>
                    @endforeach
                </ol>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-rule pt-3 text-sm">
                    <p class="text-ink-muted">{{ __('"Doesn\'t send this" means a :one\'s system does not report that figure. It does not mean zero.', ['one' => $overview->one]) }}</p>
                    <div class="flex items-center gap-4">
                        <a href="{{ route('body.guide') }}" class="text-ink underline underline-offset-2 hover:text-ink-soft">{{ __('Read the full guide') }}</a>
                        @if ($canAct)
                            <form method="POST" action="{{ route('body.guide.hide') }}">
                                @csrf
                                <button type="submit" class="rounded border border-rule-strong px-3 py-1 text-ink-soft hover:bg-surface-sunk">{{ __('I know my way, hide this') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        {{-- 3. ONE ROW PER SCHOOL. --}}
        <section id="schools" class="mb-8 scroll-mt-4">
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
                                        <a href="{{ auth()->user()->can('finding.view') ? route('body.findings.index', ['institution' => $row->institution->uuid]) : '#look-into' }}" class="underline-offset-2 hover:underline">
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

    {{-- 4. WHAT TO ASK ABOUT, MOST SERIOUS FIRST. --}}
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
        5. THE MONEY, SCHOOL BY SCHOOL.

        A total is only given when every school sent that figure. A total of the
        schools that happened to send it reads as the whole body's position when it
        is not, so otherwise the cell says how many sent it (brief section 7.4).
    --}}
    <section id="money" class="mb-8 scroll-mt-4">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Money for :month', ['month' => period_text($period)]) }}</h2>
            @can('consolidation.view')
                <a href="{{ route('body.consolidation', ['period' => $period]) }}" class="text-sm text-ink-muted hover:underline">{{ __('All schools together') }}</a>
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
                        <th class="px-4 py-2 font-semibold">{{ __('Books add up') }}</th>
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
                                    <span class="text-xs text-emerald-700">{{ __('yes') }}</span>
                                @else
                                    {{-- Accepted at ingest on purpose: refusing it would
                                         make this institution look silent rather than alarming. --}}
                                    <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-800">{{ __('no') }}</span>
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

    {{-- 6. FEES, SCHOOL BY SCHOOL. --}}
    <section id="fees" class="mb-8 scroll-mt-4">
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

    {{-- 7. BUDGETS, FROM EACH SCHOOL'S OWN BUDGET SHEET. --}}
    @if ($budgetSheets->isNotEmpty())
        <section>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Budgets up to the end of :month', ['month' => period_text($period)]) }}</h2>
                @can('consolidation.view')
                    <a href="{{ route('body.budgets.compare', ['period' => $period]) }}" class="text-sm text-ink-muted hover:underline">{{ __('Budgets compared') }}</a>
                @endcan
            </div>

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
