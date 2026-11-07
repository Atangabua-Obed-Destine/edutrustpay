@extends('layouts.console')

@section('title', __('Administration'))
@section('subtitle', __('Every body on the platform'))

@section('content')

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        @foreach ([
            [__('Bodies'), $bodies->count()],
            [__('Institutions'), $institutions->count()],
            [__('Current reports'), $reportCount],
        ] as [$label, $value])
            <div class="rounded border border-rule bg-surface p-4">
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @php
        $queueStuck = $health['oldest'] && $health['oldest']->lt(now()->subMinutes(5));
        $sweepLate = ! $health['lastSweep'] || $health['lastSweep']->lt(now()->subHours(26));
    @endphp

    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('System health') }}</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            <div @class(['rounded border bg-surface p-4', 'border-rose-300' => $queueStuck, 'border-rule' => ! $queueStuck])>
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Reports waiting to be processed') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $health['queued'] }}</p>
                <p @class(['mt-1 text-xs', 'font-medium text-rose-700' => $queueStuck, 'text-ink-muted' => ! $queueStuck])>
                    {{ $queueStuck
                        ? __('Waiting since :time. The queue worker is not running, so these will not reach the console.', ['time' => $health['oldest']->diffForHumans()])
                        : __('Reports are accepted instantly and processed in the background.') }}
                </p>
            </div>
            <div @class(['rounded border bg-surface p-4', 'border-rose-300' => $sweepLate, 'border-rule' => ! $sweepLate])>
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Daily silence check') }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ $health['lastSweep']?->diffForHumans() ?? __('never run') }}</p>
                <p @class(['mt-1 text-xs', 'font-medium text-rose-700' => $sweepLate, 'text-ink-muted' => ! $sweepLate])>
                    {{ $sweepLate
                        ? __('The scheduler is not running, so a school that goes silent will not raise a finding.')
                        : __('Looks for schools that have stopped reporting.') }}
                </p>
            </div>
            <div @class(['rounded border bg-surface p-4', 'border-rose-300' => $health['failed'] > 0, 'border-rule' => $health['failed'] === 0])>
                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Failed processing jobs') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $health['failed'] }}</p>
                <p class="mt-1 text-xs text-ink-muted">{{ __('Should stay at zero.') }}</p>
            </div>
        </div>
    </section>

    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Bodies') }}</h2>
        <div class="overflow-hidden rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($bodies as $b)
                        <tr>
                            {{-- Indented by depth: the tree is the point, and a flat
                                 list of bodies hides who is inside whom. --}}
                            <td class="px-4 py-2" style="padding-left: {{ 16 + $b->depth * 20 }}px">
                                <span class="font-medium text-ink">{{ $b->name }}</span>
                                <span class="ml-2 text-xs text-ink-muted">{{ $b->type }}</span>
                            </td>
                            <td class="px-4 py-2 text-right text-xs text-ink-muted">
                                {{ trans_choice('{0}no institutions|{1}1 institution|[2,*]:count institutions', $b->institutions_count, ['count' => $b->institutions_count]) }}
                            </td>
                            <td class="px-4 py-2 font-mono text-xs text-ink-muted">{{ $b->path }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Recent ingest activity') }}</h2>
        <div class="overflow-hidden rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <tbody class="divide-y divide-rule bg-surface">
                    @forelse ($recentEvents as $event)
                        <tr>
                            <td class="px-4 py-2 text-xs text-ink-muted">{{ $event->created_at?->diffForHumans() }}</td>
                            <td class="px-4 py-2 text-ink-soft">{{ $event->institution->name }}</td>
                            <td class="px-4 py-2 text-xs">{{ $event->type }}</td>
                            <td class="px-4 py-2 text-ink-muted">{{ $event->summary }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-3 text-sm italic text-ink-muted">{{ __('No ingest activity yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

@endsection
