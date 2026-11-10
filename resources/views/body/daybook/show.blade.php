@extends('layouts.console')

@section('title', __(':name daybook', ['name' => $institution->name]))
@section('subtitle', period_text($period))

@section('header-actions')
    <x-print-button />
    @if ($filing)
        <a href="{{ route('body.institutions.daybook.csv', ['institution' => $institution, 'period' => $period]) }}"
           class="no-print inline-flex items-center gap-1.5 rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">{{ __('Download for Excel') }}</a>
    @endif
    <form method="GET" class="flex items-center gap-2">
        <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
        <select name="period" id="period" onchange="this.form.submit()" class="rounded border-rule-strong bg-surface py-1.5 text-sm">
            @unless ($filings->contains('period', $period))
                <option value="{{ $period }}" selected>{{ period_text($period) }}</option>
            @endunless
            @foreach ($filings as $option)
                <option value="{{ $option->period }}" @selected($option->period === $period)>{{ period_text($option->period) }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')

    <x-breadcrumb :items="[__('All institutions') => route('body.institutions.index'), $institution->name => route('body.institutions.show', ['institution' => $institution, 'period' => $period]), __('Daybook') => null]" />

    @if (! $filing)
        <div class="rounded border border-dashed border-rule-strong bg-surface p-8 text-center">
            <p class="text-sm font-medium text-ink">{{ __(':name has not sent its daybook for :month', ['name' => $institution->name, 'month' => period_text($period)]) }}</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-ink-muted">{{ __('This does not mean no money moved. It means nothing has arrived here yet.') }}</p>
        </div>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                [__('Money in'), money_text($totals->money_in), ''],
                [__('Money out'), money_text($totals->money_out), ''],
                [__('In less out'), money_text((float) $totals->money_in - (float) $totals->money_out), (float) $totals->money_in < (float) $totals->money_out ? 'text-rose-700' : ''],
                [__('Entries'), number_format($totals->entries), ''],
            ] as [$label, $value, $colour])
                <div class="rounded border border-rule bg-surface p-4">
                    <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }}</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums {{ $colour }}">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded border border-rule bg-surface px-4 py-3">
            <input type="hidden" name="period" value="{{ $period }}">
            <div class="min-w-48 flex-1">
                <label for="q" class="block text-xs font-medium text-ink-muted">{{ __('Search') }}</label>
                <input type="search" id="q" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('A name, a reference, a description') }}"
                       class="mt-1 w-full rounded border border-rule-strong bg-surface px-2 py-1.5 text-sm">
            </div>
            <div>
                <label for="direction" class="block text-xs font-medium text-ink-muted">{{ __('Direction') }}</label>
                <select id="direction" name="direction" class="mt-1 rounded border border-rule-strong bg-surface py-1.5 text-sm">
                    <option value="">{{ __('In and out') }}</option>
                    <option value="in" @selected($filters['direction'] === 'in')>{{ __('Money in') }}</option>
                    <option value="out" @selected($filters['direction'] === 'out')>{{ __('Money out') }}</option>
                </select>
            </div>
            <div>
                <label for="kind" class="block text-xs font-medium text-ink-muted">{{ __('Type') }}</label>
                <select id="kind" name="kind" class="mt-1 rounded border border-rule-strong bg-surface py-1.5 text-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach (\App\Models\DaybookLine::KINDS as $key => $label)
                        <option value="{{ $key }}" @selected($filters['kind'] === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="method" class="block text-xs font-medium text-ink-muted">{{ __('Paid by') }}</label>
                <select id="method" name="method" class="mt-1 rounded border border-rule-strong bg-surface py-1.5 text-sm">
                    <option value="">{{ __('Any') }}</option>
                    @foreach (\App\Models\DaybookLine::METHODS as $key => $label)
                        <option value="{{ $key }}" @selected($filters['method'] === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">{{ __('Show') }}</button>
            @if (array_filter($filters))
                <a href="{{ route('body.institutions.daybook', ['institution' => $institution, 'period' => $period]) }}" class="py-1.5 text-sm text-ink-muted hover:underline">{{ __('Clear') }}</a>
            @endif
        </form>

        @if ($lines->isEmpty())
            <p class="rounded border border-rule bg-surface px-4 py-3 text-sm text-ink-muted">
                {{ array_filter($filters) ? __('Nothing matches this filter.') : __(':name recorded no money moving in :month.', ['name' => $institution->name, 'month' => period_text($period)]) }}
            </p>
        @else
            <div class="overflow-x-auto rounded border border-rule">
                <table class="min-w-full divide-y divide-rule text-sm">
                    <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th class="px-4 py-2 font-semibold">{{ __('Date') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Reference') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Description') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Budget line') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Paid by') }}</th>
                            <th class="px-4 py-2 text-right font-semibold">{{ __('In') }}</th>
                            <th class="px-4 py-2 text-right font-semibold">{{ __('Out') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule bg-surface">
                        @foreach ($lines as $line)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2 text-ink-soft">{{ $line->line_date->format('j M') }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-xs text-ink-muted">{{ $line->ref }}</td>
                                <td class="px-4 py-2 text-ink">
                                    {{ $line->description }}
                                    @if ($line->party && ! str_contains($line->description, $line->party))
                                        <span class="block text-xs text-ink-muted">{{ $line->party }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-ink-soft">{{ $line->heading }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-ink-soft">{{ $line->method ? __(\App\Models\DaybookLine::METHODS[$line->method]) : '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $line->direction === 'in' ? money_text($line->amount) : '' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $line->direction === 'out' ? money_text($line->amount) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $lines->links() }}</div>
        @endif

        <p class="mt-4 text-xs text-ink-muted">
            {{ __('Amounts are in :currency. Sent by :name on :date.', ['currency' => $filing->currency, 'name' => $institution->name, 'date' => $filing->received_at->toFormattedDateString()]) }}
            @if ($filing->sequence > 1)
                {{ __('This is version :version: the month was corrected after it was first sent, and the earlier versions are kept.', ['version' => $filing->sequence]) }}
            @endif
            {{ __('This page names people, so each time it is opened is recorded.') }}
        </p>
    @endif

@endsection
