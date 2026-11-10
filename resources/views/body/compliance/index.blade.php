@extends('layouts.console')

@section('title', __('Compliance'))
@section('subtitle', __('Are the schools following the rules you set?'))

@section('header-actions')
    <x-print-button />
    <a href="{{ route('body.compliance.regulations.index') }}"
       class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
        {{ __('Rules') }}
    </a>
    @can('compliance.manage')
        <a href="{{ route('body.compliance.regulations.create') }}"
           class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('New fee schedule') }}
        </a>
    @endcan
@endsection

@section('content')

    @if ($regulations->isEmpty())
        <div class="rounded border border-dashed border-rule-strong bg-surface p-8 text-center">
            <p class="text-sm font-medium text-ink">{{ __('No rules yet') }}</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-ink-muted">
                {{ __('Enter a rule, such as a fee schedule, and every school it applies to is compared with it automatically. Rules the system cannot measure can be confirmed by a person.') }}
            </p>
            @can('compliance.manage')
                <a href="{{ route('body.compliance.regulations.create') }}"
                   class="mt-4 inline-block rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                    {{ __('Create the first rule') }}
                </a>
            @endcan
        </div>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            @foreach ([
                ['breach', __('Rules broken'), 'text-rose-700'],
                ['unknown', __('Cannot be checked'), 'text-amber-700'],
                ['complies', __('Complying'), 'text-emerald-700'],
            ] as [$key, $label, $colour])
                <div class="rounded border border-rule bg-surface p-4">
                    <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums {{ $totals[$key] > 0 ? $colour : '' }}">{{ $totals[$key] }}</p>
                </div>
            @endforeach
        </div>

        <div class="mb-3 overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('School') }}</th>
                        @foreach ($regulations as $regulation)
                            <th class="px-4 py-2 font-semibold">{{ $regulation->title }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-ink">
                                {{ $row->institution->name }}
                                <span class="block text-xs font-normal text-ink-muted">{{ $row->institution->body->name }}</span>
                            </td>
                            @foreach ($regulations as $regulation)
                                @php $cell = $row->cells[$regulation->id]; @endphp
                                <td class="px-4 py-2 align-top">
                                    @if ($cell)
                                        <a href="{{ route('body.compliance.show', [$regulation->id, $row->institution]) }}" class="group block">
                                            @include('body.compliance.partials.state', ['state' => $cell->state])
                                            <span class="mt-1 block max-w-56 text-xs leading-snug text-ink-muted group-hover:underline">{{ $cell->summary }}</span>
                                        </a>
                                    @else
                                        <span class="text-xs text-ink-muted" title="{{ __('This rule does not apply to this type of institution.') }}">{{ __('does not apply') }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mb-8 text-xs text-ink-muted">
            {{ __('This compares what is recorded in each school\'s system. Money collected off the books cannot show here, so a clean board is not proof of a clean school.') }}
        </p>
    @endif

    <section>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Open follow-ups') }}</h2>

        @if ($cases->isEmpty())
            <p class="rounded border border-rule bg-surface px-4 py-3 text-sm text-ink-muted">{{ __('None open.') }}</p>
        @else
            <div class="overflow-x-auto rounded border border-rule">
                <table class="min-w-full divide-y divide-rule text-sm">
                    <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th class="px-4 py-2 font-semibold">{{ __('School') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Rule') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Assigned to') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Due') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule bg-surface">
                        @foreach ($cases as $case)
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ route('body.compliance.show', [$case->regulation_id, $case->institution]) }}" class="font-medium text-ink hover:underline">{{ $case->institution->name }}</a>
                                </td>
                                <td class="px-4 py-2 text-ink-soft">{{ $case->regulation->title }}</td>
                                <td class="px-4 py-2 text-ink-soft">{{ $case->assignee?->name ?? __('nobody yet') }}</td>
                                <td class="px-4 py-2 {{ $case->isOverdue() ? 'font-medium text-rose-700' : 'text-ink-soft' }}">
                                    {{ $case->due_on?->toFormattedDateString() ?? '—' }}
                                    @if ($case->isOverdue()) · {{ __('overdue') }} @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

@endsection
