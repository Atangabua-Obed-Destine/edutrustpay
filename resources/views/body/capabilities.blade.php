@extends('layouts.console')

@section('title', __('Capability matrix'))
@section('subtitle', __('What each institution can currently report'))

@section('content')

    <p class="mb-5 max-w-3xl text-sm text-ink-soft">
        {{ __('This page explains every "not yet reporting" elsewhere in the console. A capability an institution has not declared is a figure it cannot produce — never a zero. Gaps here close as the underlying systems gain modules; a cell marked "not applicable" never will.') }}
    </p>

    @php
        $cellStyles = [
            'reporting'      => ['bg-emerald-100 text-emerald-800', __('reporting')],
            'declared'       => ['bg-amber-100 text-amber-800', __('declared, not seen')],
            'not_reporting'  => ['bg-surface-sunk text-ink-muted', __('not yet')],
            'not_applicable' => ['bg-transparent text-ink-muted opacity-60', __('n/a')],
        ];
    @endphp

    <div class="overflow-x-auto rounded border border-rule">
        <table class="min-w-full divide-y divide-rule text-sm">
            <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    <th class="px-4 py-2 font-semibold">{{ __('Institution') }}</th>
                    @foreach ($capabilities as $capability)
                        <th class="px-3 py-2 text-center font-semibold">{{ str_replace('_', ' ', $capability) }}</th>
                    @endforeach
                    <th class="px-4 py-2 text-center font-semibold">{{ __('Coverage') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule bg-surface">
                @foreach ($rows as $row)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2">
                            <a href="{{ route('body.institutions.show', $row->institution) }}"
                               class="font-medium text-ink hover:underline">{{ $row->institution->name }}</a>
                            <span class="ml-2 text-xs text-ink-muted">{{ $row->institution->system }}</span>
                        </td>

                        @foreach ($capabilities as $capability)
                            @php [$classes, $label] = $cellStyles[$row->cells[$capability]]; @endphp
                            <td class="px-3 py-2 text-center">
                                <span class="inline-block rounded px-1.5 py-0.5 text-[11px] font-medium {{ $classes }}">{{ $label }}</span>
                            </td>
                        @endforeach

                        <td class="px-4 py-2 text-center text-xs font-medium tabular-nums text-ink-soft">
                            {{ $row->coverage }} / {{ $row->applicable }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap gap-3 text-xs text-ink-muted">
        @foreach ($cellStyles as $state => [$classes, $label])
            <span class="inline-flex items-center gap-1.5">
                <span class="inline-block rounded px-1.5 py-0.5 font-medium {{ $classes }}">{{ $label }}</span>
            </span>
        @endforeach
    </div>

@endsection
