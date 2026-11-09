@extends('layouts.console')

@section('title', $institution->name)
@section('subtitle', $institution->body->name . ' · ' . \App\Support\InstitutionType::shortLabel($institution->type) . ' · ' . $institution->system)

@php
    $forOperator = request()->routeIs('admin.*');
    $eventNames = [
        'received' => __('Received'), 'rejected' => __('Refused'), 'restated' => __('Corrected'),
        'heartbeat' => __('Checked in'), 'stale' => __('Gone quiet'), 'recovered' => __('Back in touch'),
    ];
@endphp

@section('header-actions')
    @unless (request()->routeIs('admin.*'))
        <a href="{{ route('body.institutions.show', $institution) }}"
           class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
            {{ __('See its figures') }}
        </a>
    @endunless
    @can('institution.update')
        <a href="{{ manage_route('institutions.edit', $institution) }}"
           class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
            {{ __('Edit') }}
        </a>
    @endcan
@endsection

@section('content')

    <x-breadcrumb :items="[
        __('Institutions') => manage_route('institutions.index'),
        ...($forOperator ? [$institution->body->name => route('admin.bodies.show', $institution->body)] : []),
        $institution->name => null,
    ]" />

    @if ($checklist['blocking'])
        {{-- Said out loud rather than left as an unticked box among nine: an
             unmapped chart of accounts is what makes schools incomparable. --}}
        <div class="mb-6 rounded border border-amber-300 bg-amber-50 px-4 py-3">
            <p class="text-sm font-semibold text-amber-900">{{ __('Setting up is not finished') }}</p>
            <p class="mt-1 text-sm text-amber-800">
                {{ __('Still outstanding: :items. Until this is done, this school\'s figures may not compare fairly with other schools.', ['items' => implode('; ', $checklist['blocking'])]) }}
            </p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            {{-- CAPABILITIES: the most consequential screen in the operator area. --}}
            <x-card :title="__('What its system sends')"
                    :subtitle="__('Ticks itself as reports arrive. Tick a part ahead of time only if its system is expected to send it. Anything not sent shows as “doesn\'t send this”, never as zero.')">
                <form method="POST" action="{{ manage_route('institutions.capabilities', $institution) }}">
                    @csrf @method('PUT')

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($capabilities as $capability)
                            @php
                                $isApplicable = in_array($capability, $applicable, true);
                                $row = $declared->get($capability);
                                $seen = $row?->first_seen_at !== null;
                            @endphp

                            <label @class([
                                'flex items-start gap-2 rounded border px-3 py-2 text-sm',
                                'border-rule' => $isApplicable,
                                'border-transparent bg-surface-sunk opacity-60' => ! $isApplicable,
                            ])>
                                <input type="checkbox" name="capabilities[]" value="{{ $capability }}"
                                       @checked($row !== null)
                                       @disabled(! $isApplicable || $seen)
                                       class="mt-0.5 rounded border-rule-strong">

                                <span class="min-w-0">
                                    <span class="font-medium">{{ \App\Support\CapabilityText::name($capability) }}</span>

                                    @if (! $isApplicable)
                                        <span class="block text-xs text-ink-muted">
                                            {{ __('not applicable to a :type', ['type' => mb_strtolower(\App\Support\InstitutionType::shortLabel($institution->type))]) }}
                                        </span>
                                    @elseif ($seen)
                                        {{-- Reports carrying it have already arrived. Un-ticking
                                             it would make this page disagree with the figures. --}}
                                        <span class="block text-xs text-emerald-700">
                                            {{ __('being sent, so it stays ticked') }}
                                        </span>
                                        <input type="hidden" name="capabilities[]" value="{{ $capability }}">
                                    @elseif ($row)
                                        <span class="block text-xs text-amber-700">
                                            {{ __('expected, nothing received yet') }}
                                        </span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @can('capability.declare')
                        <button type="submit"
                                class="mt-4 rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white">
                            {{ __('Save') }}
                        </button>
                    @endcan
                </form>
            </x-card>

            {{-- CREDENTIALS --}}
            @can('credential.view')
            <x-card :title="__('Connection keys')"
                    :subtitle="__('The key its system uses to send reports here. When someone who held it leaves, replace it or revoke it: each takes one click.')">
                @if ($credentials->isEmpty())
                    <p class="mb-3 text-sm text-ink-muted">
                        {{ __('No key yet. Until one is issued its system cannot send anything, and that looks the same as going quiet.') }}
                    </p>
                @else
                    <div class="-mx-4 mb-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-rule text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th class="px-4 py-2 font-semibold">{{ __('Key') }}</th>
                                    <th class="px-4 py-2 font-semibold">{{ __('Label') }}</th>
                                    <th class="px-4 py-2 font-semibold">{{ __('Last used') }}</th>
                                    <th class="px-4 py-2 font-semibold">{{ __('State') }}</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-rule">
                                @foreach ($credentials as $credential)
                                    <tr>
                                        <td class="px-4 py-2 font-mono text-xs">{{ $credential->key_id }}</td>
                                        <td class="px-4 py-2 text-ink-muted">{{ $credential->label ?: '—' }}</td>
                                        <td class="px-4 py-2 text-ink-muted">
                                            {{ $credential->last_used_at?->diffForHumans() ?? __('never') }}
                                        </td>
                                        <td class="px-4 py-2">
                                            @if ($credential->isInGracePeriod())
                                                <span class="text-xs text-amber-700" title="{{ $credential->revoked_reason }}">
                                                    {{ __('replaced, works until :date', ['date' => $credential->graceEndsAt()->toDayDateTimeString()]) }}
                                                </span>
                                            @elseif ($credential->isActive())
                                                <span class="text-xs text-emerald-700">{{ __('active') }}</span>
                                            @else
                                                <span class="text-xs text-ink-muted" title="{{ $credential->revoked_reason }}">
                                                    {{ __('revoked') }} {{ $credential->revoked_at?->toFormattedDateString() }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            @if ($credential->isActive())
                                                <div class="flex justify-end gap-2">
                                                    @if (! $credential->isInGracePeriod() && auth()->user()->can('credential.rotate'))
                                                    <form method="POST" action="{{ manage_route('credentials.rotate', [$institution, $credential]) }}"
                                                          data-confirm="{{ __('Issue a replacement key? The current one keeps working until you revoke it.') }}">
                                                        @csrf
                                                        <button type="submit" class="text-xs text-ink-soft hover:underline">{{ __('Replace') }}</button>
                                                    </form>
                                                    @endif
                                                    @can('credential.revoke')
                                                    <button type="button" class="text-xs text-rose-600 hover:underline"
                                                            onclick="document.getElementById('revoke-{{ $credential->id }}').hidden = false">
                                                        {{ __('Revoke') }}
                                                    </button>
                                                    @endcan
                                                </div>
                                            @endif
                                        </td>
                                    </tr>

                                    @if ($credential->isActive())
                                        <tr id="revoke-{{ $credential->id }}" hidden>
                                            <td colspan="5" class="bg-rose-50 px-4 py-3">
                                                <form method="POST" action="{{ manage_route('credentials.revoke', [$institution, $credential]) }}"
                                                      class="flex flex-wrap items-end gap-2">
                                                    @csrf
                                                    <div class="min-w-64 flex-1">
                                                        <label class="block text-xs font-medium text-rose-900">
                                                            {{ __('Why is this key being revoked?') }}
                                                        </label>
                                                        <input type="text" name="reason" required minlength="3"
                                                               placeholder="{{ __('e.g. bursar left on 30 August') }}"
                                                               class="mt-1 w-full rounded border border-rose-300 px-3 py-1.5 text-sm">
                                                    </div>
                                                    <button type="submit" class="rounded bg-rose-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-rose-700">
                                                        {{ __('Revoke now') }}
                                                    </button>
                                                    <button type="button" class="px-2 py-1.5 text-sm text-rose-800"
                                                            onclick="document.getElementById('revoke-{{ $credential->id }}').hidden = true">
                                                        {{ __('Cancel') }}
                                                    </button>
                                                </form>
                                                <p class="mt-2 text-xs text-rose-800">
                                                    {{ __('The record is kept, never deleted, so a report signed with this key can still be explained later.') }}
                                                </p>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @can('credential.issue')
                <form method="POST" action="{{ manage_route('credentials.store', $institution) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <div class="min-w-56 flex-1">
                        <label for="label" class="block text-xs text-ink-muted">{{ __('Label (optional)') }}</label>
                        <input type="text" name="label" id="label" placeholder="{{ __('e.g. bursary computer') }}"
                               class="mt-1 w-full rounded border border-rule-strong px-3 py-1.5 text-sm">
                    </div>
                    <button type="submit" class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white">
                        {{ __('Issue a key') }}
                    </button>
                </form>
                @endcan
            </x-card>
            @endcan

            {{-- ONBOARDING --}}
            <x-card :title="__('Getting started')"
                    :subtitle="$forOperator
                        ? __(':done of :total complete. Visible to the body too.', ['done' => $checklist['complete'], 'total' => $checklist['total']])
                        : __(':done of :total done.', ['done' => $checklist['complete'], 'total' => $checklist['total']])">
                <div class="mb-4 h-1.5 w-full overflow-hidden rounded-full bg-surface-sunk">
                    <div class="h-full rounded-full bg-emerald-500" style="width: {{ $checklist['percent'] }}%"></div>
                </div>

                <ul class="space-y-2">
                    @foreach ($checklist['steps'] as $step)
                        <li class="flex items-start gap-3 rounded border border-rule px-3 py-2">
                            @if ($step->manual && auth()->user()->can('institution.update'))
                                <form method="POST" action="{{ manage_route('institutions.onboarding', $institution) }}" class="pt-0.5">
                                    @csrf
                                    <input type="hidden" name="step" value="{{ $step->key }}">
                                    <input type="hidden" name="done" value="{{ $step->done ? 0 : 1 }}">
                                    <button type="submit" aria-label="{{ $step->label }}"
                                            @class([
                                                'flex h-4 w-4 items-center justify-center rounded border text-[10px] font-bold',
                                                'border-emerald-500 bg-emerald-500 text-white' => $step->done,
                                                'border-rule-strong' => ! $step->done,
                                            ])>{{ $step->done ? '✓' : '' }}</button>
                                </form>
                            @else
                                {{-- Observed from the data. Asking somebody to tick a box the
                                     database can answer is how checklists start lying. --}}
                                <span @class([
                                    'mt-0.5 flex h-4 w-4 items-center justify-center rounded text-[10px] font-bold',
                                    'bg-emerald-500 text-white' => $step->done,
                                    'bg-surface-sunk text-ink-muted' => ! $step->done,
                                ])>{{ $step->done ? '✓' : '·' }}</span>
                            @endif

                            <div class="min-w-0 flex-1">
                                <p @class(['text-sm', 'text-ink-muted line-through' => $step->done])>{{ $step->label }}</p>
                                <p class="text-xs text-ink-muted">{{ $step->why }}</p>
                                @if ($step->done && $step->completed_by)
                                    <p class="mt-0.5 text-[11px] text-ink-muted">
                                        {{ $step->completed_by }}{{ $step->completed_at ? ' · ' . $step->completed_at->toFormattedDateString() : '' }}
                                    </p>
                                @endif
                            </div>

                            @unless ($step->manual)
                                <span class="text-[11px] text-ink-muted">{{ __('ticks itself') }}</span>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card :title="__('Its reference')">
                <p class="text-xs text-ink-muted">{{ $forOperator ? __('The institution_ref its client must send.') : __('Its system sends this with every report, so the console knows whose report it is. Whoever sets up its system needs it, with the key.') }}</p>
                <code id="etp-ref" class="mt-1 block break-all rounded bg-surface-sunk px-2 py-1.5 font-mono text-xs">{{ $institution->uuid }}</code>
                <button type="button" data-copy="#etp-ref"
                        class="mt-2 text-xs text-ink-soft underline">{{ __('Copy reference') }}</button>
            </x-card>

            <x-card :title="__('Is it sending?')">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('Last report') }}</dt>
                        <dd class="text-right font-medium">{{ $institution->last_report_at?->diffForHumans() ?? __('never') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('Last check-in') }}</dt>
                        <dd class="text-right font-medium">{{ $institution->last_heartbeat_at?->diffForHumans() ?? __('never') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('Warn after') }}</dt>
                        <dd class="text-right font-medium">{{ $institution->notReportingDays() }} {{ __('days') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('State') }}</dt>
                        <dd class="text-right font-medium">
                            @if ($institution->isSilent())
                                <span class="text-rose-600">{{ __('gone quiet') }}</span>
                            @else
                                @if ($institution->last_report_at)
                                    <span class="text-emerald-700">{{ __('up to date') }}</span>
                                @else
                                    <span class="text-amber-700">{{ __('nothing sent yet') }}</span>
                                @endif
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-card>

            @if ($recentReports->isNotEmpty())
                <x-card :title="__('Recent months')">
                    <ul class="space-y-1 text-sm">
                        @foreach ($recentReports as $report)
                            <li class="flex items-center justify-between">
                                <span class="text-xs">{{ period_text($report->period) }}</span>
                                <span class="text-xs text-ink-muted">
                                    {{ $report->status === 'final' ? __('closed') : __('still open') }}@if ($report->sequence > 1) · {{ __('version :n', ['n' => $report->sequence]) }}@endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif

            @if ($events->isNotEmpty())
                <x-card :title="__('Recent activity')">
                    <ul class="space-y-2 text-xs">
                        @foreach ($events as $event)
                            <li>
                                <span class="text-ink-muted">{{ $event->created_at?->diffForHumans() }}</span>
                                <span class="ml-1 font-medium">{{ $eventNames[$event->type] ?? $event->type }}</span>
                                <p class="text-ink-muted">{{ $event->summary }}</p>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </div>
    </div>

@endsection
