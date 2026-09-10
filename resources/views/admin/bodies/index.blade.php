@extends('layouts.console')

@section('title', __('Bodies'))
@section('subtitle', __('Organisations that own institutions. A body may contain other bodies.'))

@section('header-actions')
    <a href="{{ route('admin.bodies.create') }}"
       class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
        {{ __('New body') }}
    </a>
@endsection

@section('content')

    @if ($bodies->isEmpty())
        {{-- First run. Say what to do rather than showing an empty table. --}}
        <div class="rounded border border-dashed border-rule-strong bg-surface p-8 text-center">
            <p class="text-sm font-medium text-ink">{{ __('No bodies yet') }}</p>
            <p class="mx-auto mt-1 max-w-lg text-sm text-ink-muted">
                {{ __('A body is the organisation that owns institutions — an archdiocese, a diocese, a school group. Create the top-level one first; deaneries and parishes go inside it afterwards.') }}
            </p>
            <a href="{{ route('admin.bodies.create') }}"
               class="mt-4 inline-block rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                {{ __('Create the first body') }}
            </a>
        </div>
    @else
        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('Body') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Type') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Institutions') }}</th>
                        <th class="px-4 py-2 text-right font-semibold">{{ __('Contains') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Fiscal year') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Status') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($bodies as $body)
                        <tr>
                            {{-- Indented by depth: the nesting IS the model, and a
                                 flat list hides who sits inside whom. --}}
                            <td class="px-4 py-2" style="padding-left: {{ 16 + $body->depth * 22 }}px">
                                <a href="{{ route('admin.bodies.show', $body) }}"
                                   class="font-medium text-ink hover:underline">{{ $body->name }}</a>
                                @if ($body->city)
                                    <span class="ml-2 text-xs text-ink-muted">{{ $body->city }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-ink-muted">{{ $body->type ?: '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $body->institutions_count }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-ink-muted">
                                {{ $body->children_count ?: '—' }}
                            </td>
                            <td class="px-4 py-2 text-ink-muted">
                                {{ \Carbon\Carbon::create(null, $body->fiscal_year_start_month, 1)->format('F') }}
                            </td>
                            <td class="px-4 py-2">
                                @if ($body->is_active)
                                    <span class="text-xs text-emerald-700">{{ __('active') }}</span>
                                @else
                                    <span class="rounded bg-surface-sunk px-1.5 py-0.5 text-xs text-ink-soft">{{ __('inactive') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    {{-- "Show me what they are seeing." Reproducing a
                                         bursary head's complaint by reasoning about
                                         scopes and capability declarations is slow and
                                         gets it wrong; looking at their page does not.
                                         Read-only, and it says so once you are in. --}}
                                    <form method="POST" action="{{ route('admin.bodies.impersonate', $body) }}">
                                        @csrf
                                        <button type="submit"
                                                class="text-xs font-medium text-ink-soft hover:underline">
                                            {{ __('View as this body') }}
                                        </button>
                                    </form>
                                    <a href="{{ route('admin.bodies.create', ['parent' => $body->id]) }}"
                                       class="text-xs text-ink-muted hover:underline">{{ __('Add inside') }}</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
