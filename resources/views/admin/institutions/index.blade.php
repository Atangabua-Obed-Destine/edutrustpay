@extends('layouts.console')

@section('title', __('Institutions'))
@section('subtitle', __('Schools, hospitals, pharmacies and businesses that push reports.'))

@section('header-actions')
    <a href="{{ route('admin.institutions.create') }}"
       class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
        {{ __('Enrol institution') }}
    </a>
@endsection

@section('content')

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label for="body" class="block text-xs text-ink-muted">{{ __('Body') }}</label>
            <select name="body" id="body" onchange="this.form.submit()"
                    class="mt-1 rounded border-rule-strong py-1.5 text-sm">
                <option value="">{{ __('All bodies') }}</option>
                @foreach ($bodies as $b)
                    <option value="{{ $b->id }}" @selected((string) $filters['body'] === (string) $b->id)>{{ $b->indented_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="system" class="block text-xs text-ink-muted">{{ __('System') }}</label>
            <select name="system" id="system" onchange="this.form.submit()"
                    class="mt-1 rounded border-rule-strong py-1.5 text-sm">
                <option value="">{{ __('All systems') }}</option>
                @foreach (['edutrustshsm', 'paxhitestbed', 'posinnova'] as $s)
                    <option value="{{ $s }}" @selected($filters['system'] === $s)>{{ $s }}</option>
                @endforeach
            </select>
        </div>
        @if ($filters['body'] || $filters['system'])
            <a href="{{ route('admin.institutions.index') }}" class="pb-1.5 text-sm text-ink-muted hover:underline">{{ __('Clear') }}</a>
        @endif
    </form>

    @if ($institutions->isEmpty())
        <div class="rounded border border-dashed border-rule-strong bg-surface p-8 text-center">
            <p class="text-sm font-medium text-ink">{{ __('No institutions here yet') }}</p>
            <p class="mx-auto mt-1 max-w-lg text-sm text-ink-muted">
                {{ __('Enrolling an institution creates its identity and credentials. It does not touch that institution\'s own system — the reports are pushed from there.') }}
            </p>
            <a href="{{ route('admin.institutions.create') }}"
               class="mt-4 inline-block rounded bg-accent px-4 py-2 text-sm font-semibold text-white">
                {{ __('Enrol one') }}
            </a>
        </div>
    @else
        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('Institution') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Body') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Type') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('System') }}</th>
                        <th class="px-4 py-2 text-center font-semibold">{{ __('Declared') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Credential') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Last report') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($institutions as $institution)
                        @php $hasKey = $institution->credentials()->active()->exists(); @endphp
                        <tr>
                            <td class="px-4 py-2">
                                <a href="{{ route('admin.institutions.show', $institution) }}"
                                   class="font-medium text-ink hover:underline">{{ $institution->name }}</a>
                                @unless ($institution->is_active)
                                    <span class="ml-2 rounded bg-surface-sunk px-1.5 py-0.5 text-xs text-ink-soft">{{ __('inactive') }}</span>
                                @endunless
                            </td>
                            <td class="px-4 py-2 text-ink-muted">{{ $institution->body->name }}</td>
                            <td class="px-4 py-2 text-ink-muted">{{ str_replace('_', ' ', $institution->type) }}</td>
                            <td class="px-4 py-2 text-ink-muted">{{ $institution->system }}</td>
                            <td class="px-4 py-2 text-center tabular-nums">
                                {{ $institution->capabilities_count }} / {{ count($institution->applicableCapabilities()) }}
                            </td>
                            <td class="px-4 py-2">
                                @if ($hasKey)
                                    <span class="text-xs text-emerald-700">{{ __('issued') }}</span>
                                @else
                                    {{-- Without a key it cannot report at all, and on the
                                         console that looks exactly like going quiet. --}}
                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">
                                        {{ __('none') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-ink-muted">
                                {{ $institution->last_period_received ?? __('never') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
