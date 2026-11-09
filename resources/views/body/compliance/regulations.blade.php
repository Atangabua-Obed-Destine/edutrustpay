@extends('layouts.console')

@section('title', __('Rules'))
@section('subtitle', __('What your schools are expected to follow.'))

@section('header-actions')
    @can('compliance.manage')
        <a href="{{ route('body.compliance.regulations.create', ['type' => \App\Models\Regulation::ATTESTATION]) }}"
           class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
            {{ __('New rule, confirmed by a person') }}
        </a>
        <a href="{{ route('body.compliance.regulations.create') }}"
           class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('New fee schedule') }}
        </a>
    @endcan
@endsection

@section('content')

    <x-breadcrumb :items="[__('Compliance') => route('body.compliance.index'), __('Rules') => null]" />

    @if ($regulations->isEmpty())
        <p class="rounded border border-rule bg-surface px-4 py-3 text-sm text-ink-muted">{{ __('No rules yet.') }}</p>
    @else
        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('Rule') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Area') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Checked by') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Applies to') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('From') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Status') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($regulations as $regulation)
                        <tr @class(['text-ink-muted' => ! $regulation->is_active])>
                            <td class="px-4 py-2 font-medium">{{ $regulation->title }}</td>
                            <td class="px-4 py-2">{{ __(\App\Models\Regulation::AREAS[$regulation->area] ?? $regulation->area) }}</td>
                            <td class="px-4 py-2">
                                {{ $regulation->check_type === \App\Models\Regulation::FEE_SCHEDULE
                                    ? __('The system, :count fees', ['count' => $regulation->fee_items_count])
                                    : __('A person') }}
                            </td>
                            <td class="px-4 py-2">{{ \App\Support\InstitutionType::plurals()[$regulation->applies_to] ?? __('All institutions') }}</td>
                            <td class="px-4 py-2">{{ $regulation->effective_from->toFormattedDateString() }}</td>
                            <td class="px-4 py-2">{{ $regulation->is_active ? __('in force') : __('retired') }}</td>
                            <td class="px-4 py-2">
                                @can('compliance.manage')
                                    <div class="flex items-baseline justify-end gap-3">
                                        <a href="{{ route('body.compliance.regulations.edit', $regulation) }}" class="text-xs text-ink-soft hover:underline">{{ __('Edit') }}</a>
                                        <form method="POST" action="{{ route('body.compliance.regulations.toggle', $regulation) }}"
                                              @if ($regulation->is_active) data-confirm="{{ __('Retire this rule? It leaves the board for every school.') }}" @endif>
                                            @csrf
                                            <button type="submit" class="text-xs text-ink-soft hover:underline">{{ $regulation->is_active ? __('Retire') : __('Restore') }}</button>
                                        </form>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
