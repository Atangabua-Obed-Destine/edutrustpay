@extends('layouts.console')

@section('title', __('Institutions'))
@section('subtitle', trans_choice('{1}One institution|[2,*]:count institutions', $institutions->count(), ['count' => $institutions->count()]))

@section('content')

    <div class="overflow-x-auto rounded border border-rule">
        <table class="min-w-full divide-y divide-rule text-sm">
            <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    <th class="px-4 py-2 font-semibold">{{ __('Institution') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('Body') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('System') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('Capabilities') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('Last period') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('Reporting') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule bg-surface">
                @foreach ($institutions as $institution)
                    <tr>
                        <td class="px-4 py-2">
                            <a href="{{ route('body.institutions.show', $institution) }}"
                               class="font-medium text-ink hover:underline">{{ $institution->name }}</a>
                        </td>
                        <td class="px-4 py-2 text-ink-muted">{{ $institution->body->name }}</td>
                        <td class="px-4 py-2 text-ink-muted">{{ $institution->system }}</td>
                        <td class="px-4 py-2 text-ink-muted tabular-nums">
                            {{ count($institution->declaredCapabilities()) }} / {{ count($institution->applicableCapabilities()) }}
                        </td>
                        <td class="px-4 py-2 text-ink-muted">{{ $institution->last_period_received ?? '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($notReporting->has($institution->id))
                                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-800">
                                    {{ $notReporting[$institution->id]->never_reported ? __('never reported') : __(':days days silent', ['days' => $notReporting[$institution->id]->days_silent]) }}
                                </span>
                            @else
                                <span class="text-xs text-emerald-700">{{ __('current') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
