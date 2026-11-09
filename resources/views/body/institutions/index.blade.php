@extends('layouts.console')

@section('title', __('Institutions'))
@section('subtitle', trans_choice('{1}One institution|[2,*]:count institutions', $institutions->count(), ['count' => $institutions->count()]))

@section('header-actions')
    @can('institution.create')
        <a href="{{ route('body.manage.institutions.create') }}"
           class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('Add an institution') }}
        </a>
    @endcan
@endsection

@php
    // Where every institution sits in the same place, a column saying so is noise.
    $showPart = $institutions->pluck('body_id')->unique()->count() > 1;
@endphp

@section('content')

    <div class="overflow-x-auto rounded border border-rule">
        <table class="min-w-full divide-y divide-rule text-sm">
            <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    <th class="px-4 py-2 font-semibold">{{ __('Institution') }}</th>
                    @if ($showPart)
                        <th class="px-4 py-2 font-semibold">{{ __('Part of') }}</th>
                    @endif
                    <th class="px-4 py-2 font-semibold">{{ __('Type') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('Accounting system') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('Parts sent') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('Latest month') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('Sending') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule bg-surface">
                @foreach ($institutions as $institution)
                    <tr>
                        <td class="px-4 py-2">
                            <a href="{{ route('body.institutions.show', $institution) }}"
                               class="font-medium text-ink hover:underline">{{ $institution->name }}</a>
                        </td>
                        @if ($showPart)
                            <td class="px-4 py-2 text-ink-muted">{{ $institution->body->name }}</td>
                        @endif
                        <td class="px-4 py-2 text-ink-muted">{{ \App\Support\InstitutionType::shortLabel($institution->type) }}</td>
                        <td class="px-4 py-2 text-ink-muted">{{ $institution->system }}</td>
                        <td class="px-4 py-2 text-ink-muted tabular-nums" title="{{ __('Parts of the monthly report it sends, of those that apply to it. See What schools send.') }}">
                            {{ $institution->capabilities->whereNotNull('first_seen_at')->count() }} / {{ count($institution->applicableCapabilities()) }}
                        </td>
                        <td class="px-4 py-2 text-ink-muted">{{ $institution->last_period_received ? period_text($institution->last_period_received) : __('none yet') }}</td>
                        <td class="px-4 py-2">
                            @if ($notReporting->has($institution->id))
                                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-800">
                                    {{ $notReporting[$institution->id]->never_reported ? __('never sent anything') : __('quiet for :days days', ['days' => $notReporting[$institution->id]->days_silent]) }}
                                </span>
                            @else
                                <span class="text-xs text-emerald-700">{{ __('up to date') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            @canany(['institution.update', 'capability.declare', 'credential.view'])
                                <a href="{{ route('body.manage.institutions.show', $institution) }}" class="text-xs font-medium text-ink-soft hover:underline">{{ __('Set up') }}</a>
                            @endcanany
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
