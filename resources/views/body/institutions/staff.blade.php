@extends('layouts.console')

@section('title', __(':name staff list', ['name' => $institution->name]))
@section('subtitle', period_text($period))

@section('header-actions')
    <form method="GET" class="flex items-center gap-2">
        <label for="period" class="text-sm text-ink-muted">{{ __('Month') }}</label>
        <select name="period" id="period" onchange="this.form.submit()" class="rounded border-rule-strong bg-surface py-1.5 text-sm">
            @unless ($months->contains($period))
                <option value="{{ $period }}" selected>{{ period_text($period) }}</option>
            @endunless
            @foreach ($months as $month)
                <option value="{{ $month }}" @selected($month === $period)>{{ period_text($month) }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')

    <x-breadcrumb :items="[__('All institutions') => route('body.institutions.index'), $institution->name => route('body.institutions.show', ['institution' => $institution, 'period' => $period]), __('Staff list') => null]" />

    @if (! $staff)
        <div class="rounded border border-dashed border-rule-strong bg-surface p-8 text-center">
            <p class="text-sm font-medium text-ink">{{ __(':name has not sent a staff list for :month', ['name' => $institution->name, 'month' => period_text($period)]) }}</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-ink-muted">
                {{ $months->isEmpty()
                    ? __('Its system does not send its staff yet. That does not mean it has none.')
                    : __('Choose another month above; the newest list is for :month.', ['month' => period_text($months->first())]) }}
            </p>
        </div>
    @else
        <p class="mb-4 max-w-3xl text-sm text-ink-soft">
            {{ __('Who was on the school\'s books at the end of :month, as its own system records them: staff number, name, department and post. Never pay. Opening this list is recorded.', ['month' => period_text($period)]) }}
        </p>

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            @foreach ([
                [__('Staff'), number_format((int) $staff['total'])],
                [__('Departments'), number_format(collect($staff['by_department'])->whereNotNull('name')->count())],
                [__('Posts'), number_format(collect($staff['by_designation'])->whereNotNull('name')->count())],
            ] as [$label, $value])
                <div class="rounded border border-rule bg-surface p-4">
                    <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }}</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded border border-rule bg-surface px-4 py-3">
            <input type="hidden" name="period" value="{{ $period }}">
            <div class="min-w-48 flex-1">
                <label for="q" class="block text-xs font-medium text-ink-muted">{{ __('Search') }}</label>
                <input type="search" id="q" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('A name or a staff number') }}"
                       class="mt-1 w-full rounded border border-rule-strong bg-surface px-2 py-1.5 text-sm">
            </div>
            @foreach (['department' => [__('Department'), $departments], 'designation' => [__('Post'), $designations]] as $key => [$label, $options])
                <div>
                    <label for="{{ $key }}" class="block text-xs font-medium text-ink-muted">{{ $label }}</label>
                    <select id="{{ $key }}" name="{{ $key }}" class="mt-1 max-w-56 rounded border border-rule-strong bg-surface py-1.5 text-sm">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($options->filter() as $option)
                            <option value="{{ $option }}" @selected($filters[$key] === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <button type="submit" class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white">{{ __('Show') }}</button>
            @if ($filters['q'] !== '' || $filters['department'] !== '' || $filters['designation'] !== '')
                <a href="{{ route('body.institutions.staff', ['institution' => $institution, 'period' => $period]) }}" class="py-1.5 text-sm text-ink-muted hover:underline">{{ __('Clear') }}</a>
            @endif
        </form>

        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('Staff number') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Name') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Department') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Post') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @forelse ($people as $person)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs {{ $person['number'] ? 'text-ink-soft' : 'italic text-ink-muted' }}">{{ $person['number'] ?? __('none') }}</td>
                            <td class="px-4 py-2 font-medium text-ink">{{ $person['name'] }}</td>
                            <td class="px-4 py-2 {{ $person['department'] ? 'text-ink-soft' : 'italic text-ink-muted' }}">{{ $person['department'] ?? __('not recorded') }}</td>
                            <td class="px-4 py-2 {{ $person['designation'] ? 'text-ink-soft' : 'italic text-ink-muted' }}">{{ $person['designation'] ?? __('not recorded') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-sm italic text-ink-muted">{{ __('Nobody matches.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-ink-muted">
            {{ trans_choice('{0}Showing none of :total.|{1}Showing 1 of :total.|[2,*]Showing :count of :total.', $people->count(), ['count' => $people->count(), 'total' => (int) $staff['total']]) }}
            {{ __('"Not recorded" means the school has not filled it in on its own system.') }}
        </p>
    @endif

@endsection
