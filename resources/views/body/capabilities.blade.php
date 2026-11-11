@extends('layouts.console')

@use('EdutrustPay\Contract\Capability')

@section('title', __('What schools send'))
@section('subtitle', __('And what it does not send yet'))

@php
    $names = \App\Support\CapabilityText::names();

    $cellStyles = [
        'reporting' => ['bg-emerald-100 text-emerald-800', __('sends'), __('Arrives with the monthly report.')],
        'declared' => ['bg-amber-100 text-amber-800', __('expected, never sent'), __('It was set up as something this school would send, and nothing has arrived. Either its system cannot produce it yet or the setting is wrong; whoever manages the school\'s connection can say which.')],
        'not_reporting' => ['bg-surface-sunk text-ink-muted', __('not yet'), __('Its system cannot produce this yet. The gap closes when it can.')],
        'not_applicable' => ['bg-transparent text-ink-muted opacity-60', __('does not apply'), __('Does not apply to this kind of institution, and never will.')],
    ];

    $order = [Capability::LEDGER, Capability::RECEIVABLES, Capability::PAYABLES, Capability::ENROLMENT, Capability::FEES_CHARGED, Capability::PAYROLL, Capability::BANK_RECON, Capability::INTEGRITY, Capability::BUDGET, Capability::STUDENTS, Capability::STAFF, Capability::FEES];
    // Any capability the contract gains later still gets a column, at the end.
    $order = array_merge(array_values(array_intersect($order, $capabilities)), array_values(array_diff($capabilities, $order)));

    // A document counts as arriving when the latest one held is no older than this.
    $recent = now()->subMonths(2)->format('Y-m');
@endphp

@section('content')

    <p class="mb-5 max-w-3xl text-sm text-ink-soft">
        {{ __('Wherever the console says "doesn\'t send this", this page is the reason. Something a school does not send is missing information, never a zero. Gaps close as a school\'s system gains the part that produces them.') }}
    </p>

    <div class="overflow-x-auto rounded border border-rule">
        <table class="min-w-full divide-y divide-rule text-sm">
            <thead class="bg-surface-sunk text-xs text-ink-muted">
                <tr class="text-center">
                    <th class="px-4 pt-2"></th>
                    <th class="border-l border-rule px-3 pt-2 font-semibold uppercase tracking-wide" colspan="{{ count($order) }}">{{ __('In the monthly report') }}</th>
                    <th class="border-l border-rule px-3 pt-2 font-semibold uppercase tracking-wide" colspan="2">{{ __('Sent separately') }}</th>
                </tr>
                <tr class="text-left">
                    <th class="px-4 py-2 font-semibold">{{ __('School') }}</th>
                    @foreach ($order as $i => $capability)
                        <th @class(['px-2 py-2 text-center align-bottom font-medium', 'border-l border-rule' => $i === 0])
                            title="{{ $names[$capability][1] ?? '' }}">{{ $names[$capability][0] ?? str_replace('_', ' ', $capability) }}</th>
                    @endforeach
                    <th class="border-l border-rule px-2 py-2 text-center align-bottom font-medium">{{ __('Daybook') }}</th>
                    <th class="px-2 py-2 text-center align-bottom font-medium">{{ __('Budget sheet') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule bg-surface">
                @foreach ($rows as $row)
                    @php $id = $row->institution->id; @endphp
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2">
                            <a href="{{ route('body.institutions.show', $row->institution) }}"
                               class="font-medium text-ink hover:underline">{{ $row->institution->name }}</a>
                            <span class="block text-xs text-ink-muted">{{ __(':n of :total parts of the report', ['n' => $row->coverage, 'total' => $row->applicable]) }}</span>
                        </td>

                        @foreach ($order as $i => $capability)
                            @php
                                $state = $row->cells[$capability];
                                // Schools send their budget as a budget sheet, not as a total in the report.
                                if ($capability === \EdutrustPay\Contract\Capability::BUDGET && $state === 'declared' && isset($budgetSheets[$id])) {
                                    $state = 'not_reporting';
                                }
                                [$classes, $label] = $cellStyles[$state];
                            @endphp
                            <td @class(['px-2 py-2 text-center', 'border-l border-rule' => $i === 0])>
                                <span class="inline-block rounded px-1.5 py-0.5 text-[11px] font-medium {{ $classes }}">{{ $label }}</span>
                            </td>
                        @endforeach

                        @foreach ([[$daybooks, 'border-l border-rule'], [$budgetSheets, '']] as [$held, $border])
                            @php $last = $held[$id] ?? null; @endphp
                            <td class="px-2 py-2 text-center {{ $border }}">
                                @if ($last === null)
                                    <span class="inline-block rounded bg-surface-sunk px-1.5 py-0.5 text-[11px] font-medium text-ink-muted">{{ __('not yet') }}</span>
                                @else
                                    <span class="inline-block rounded px-1.5 py-0.5 text-[11px] font-medium {{ $last >= $recent ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                        {{ $last >= $recent ? __('sends') : __('stopped') }}
                                    </span>
                                    <span class="block text-[10px] text-ink-muted">{{ __('latest :month', ['month' => \Carbon\Carbon::createFromFormat('!Y-m', $last)->translatedFormat('M Y')]) }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <dl class="mt-5 grid max-w-4xl gap-x-8 gap-y-2 text-xs text-ink-muted sm:grid-cols-2">
        @foreach ($cellStyles as [$classes, $label, $meaning])
            <div class="flex items-start gap-2">
                <dt class="shrink-0"><span class="inline-block rounded px-1.5 py-0.5 font-medium {{ $classes }}">{{ $label }}</span></dt>
                <dd>{{ $meaning }}</dd>
            </div>
        @endforeach
        <div class="flex items-start gap-2">
            <dt class="shrink-0"><span class="inline-block rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800">{{ __('stopped') }}</span></dt>
            <dd>{{ __('It used to arrive but nothing recent has: the latest month held is shown.') }}</dd>
        </div>
    </dl>

    <section class="mt-8 max-w-4xl">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('What each part is') }}</h2>
        <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
            @foreach ($order as $capability)
                <div>
                    <dt class="font-medium text-ink">{{ $names[$capability][0] ?? $capability }}</dt>
                    <dd class="text-xs text-ink-muted">{{ $names[$capability][1] ?? '' }}</dd>
                </div>
            @endforeach
            <div>
                <dt class="font-medium text-ink">{{ __('Daybook') }}</dt>
                <dd class="text-xs text-ink-muted">{{ __('Every movement of money in the month, line by line, with names.') }}</dd>
            </div>
            <div>
                <dt class="font-medium text-ink">{{ __('Budget sheet') }}</dt>
                <dd class="text-xs text-ink-muted">{{ __('Each budget line against what has been spent so far.') }}</dd>
            </div>
        </dl>
    </section>

@endsection
