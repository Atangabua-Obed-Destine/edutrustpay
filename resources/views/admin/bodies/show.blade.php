@extends('layouts.console')

@section('title', $body->name)
@section('subtitle', collect([
    $body->type,
    $body->parent ? __('inside :parent', ['parent' => $body->parent->name]) : __('top level'),
])->filter()->implode(' · '))

@section('header-actions')
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('admin.institutions.create', ['body' => $body->id]) }}"
           class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('Enrol institution') }}
        </a>
        <a href="{{ route('admin.bodies.create', ['parent' => $body->id]) }}"
           class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
            {{ __('Add body inside') }}
        </a>
        <a href="{{ route('admin.bodies.edit', $body) }}"
           class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">
            {{ __('Edit') }}
        </a>
        <form method="POST" action="{{ route('admin.bodies.impersonate', $body) }}">
            @csrf
            <button type="submit"
                    class="rounded border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-100">
                {{ __('View as this body') }}
            </button>
        </form>
    </div>
@endsection

@section('content')

    <x-breadcrumb :items="[__('Bodies') => route('admin.bodies.index'), $body->name => null]" />

    @unless ($body->is_active)
        <div class="mb-6 rounded border border-rule-strong bg-surface-sunk px-4 py-3 text-sm text-ink-soft">
            {{ __('This body is inactive. Its data is retained and nothing has been deleted.') }}
        </div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <x-card :title="__('Institutions')" :subtitle="__('Owned directly by this body.')">
                @if ($institutions->isEmpty())
                    <p class="text-sm text-ink-muted">
                        {{ __('None yet. Enrolling one generates the credentials it will use to push reports.') }}
                    </p>
                @else
                    <div class="-mx-4 -mb-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-rule text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th class="px-4 py-2 font-semibold">{{ __('Institution') }}</th>
                                    <th class="px-4 py-2 font-semibold">{{ __('System') }}</th>
                                    <th class="px-4 py-2 font-semibold">{{ __('Reports') }}</th>
                                    <th class="px-4 py-2 text-right font-semibold">{{ __('Last period') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-rule">
                                @foreach ($institutions as $institution)
                                    <tr>
                                        <td class="px-4 py-2">
                                            <a href="{{ route('admin.institutions.show', $institution) }}"
                                               class="font-medium text-ink hover:underline">{{ $institution->name }}</a>
                                        </td>
                                        <td class="px-4 py-2 text-ink-muted">{{ $institution->system }}</td>
                                        <td class="px-4 py-2 text-xs text-ink-muted">
                                            {{ trans_choice('{0}nothing declared|{1}1 capability|[2,*]:count capabilities', $institution->capabilities_count, ['count' => $institution->capabilities_count]) }}
                                        </td>
                                        <td class="px-4 py-2 text-right text-ink-muted">
                                            {{ $institution->last_period_received ?? '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            @if ($children->isNotEmpty())
                <x-card :title="__('Bodies inside this one')">
                    <ul class="divide-y divide-rule text-sm">
                        @foreach ($children as $child)
                            <li class="flex items-center justify-between py-2">
                                <a href="{{ route('admin.bodies.show', $child) }}"
                                   class="font-medium text-ink hover:underline">{{ $child->name }}</a>
                                <span class="text-xs text-ink-muted">
                                    {{ trans_choice('{0}no institutions|{1}1 institution|[2,*]:count institutions', $child->institutions_count, ['count' => $child->institutions_count]) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif

            <x-card :title="__('People')" :subtitle="__('The body\'s own users.')">
                @if ($users->isEmpty())
                    <p class="text-sm text-ink-muted">
                        {{ __('Nobody yet. Invite the bursary head so somebody sees the findings.') }}
                    </p>
                @else
                    <ul class="divide-y divide-rule text-sm">
                        @foreach ($users as $user)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <div>
                                    <span class="font-medium text-ink">{{ $user->name }}</span>
                                    <span class="ml-2 text-xs text-ink-muted">{{ $user->email }}</span>
                                </div>
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="rounded bg-surface-sunk px-1.5 py-0.5 text-ink-soft">
                                        {{ $user->roles->first()?->display_name ?? $user->role }}
                                    </span>
                                    @unless ($user->invite_accepted_at)
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800">
                                            {{ __('invitation pending') }}
                                        </span>
                                    @endunless
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <a href="{{ route('admin.users.create', ['body' => $body->id]) }}"
                   class="mt-3 inline-block text-sm text-ink-soft underline">{{ __('Invite somebody') }}</a>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card :title="__('Details')">
                <dl class="space-y-2 text-sm">
                    @foreach ([
                        __('Fiscal year starts') => \Carbon\Carbon::create(null, $body->fiscal_year_start_month, 1)->format('F'),
                        __('Currency') => $body->currency,
                        __('Silence threshold') => $body->not_reporting_days
                            ? trans_choice('{1}1 day|[2,*]:count days', $body->not_reporting_days, ['count' => $body->not_reporting_days])
                            : __(':days (platform default)', ['days' => config('findings.rules.not_reporting.days')]),
                        __('City') => $body->city ?: '—',
                        __('Region') => $body->region ?: '—',
                        __('Country') => $body->country,
                    ] as $label => $value)
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ $label }}</dt>
                            <dd class="text-right font-medium">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card :title="__('Tree position')">
                <p class="text-xs text-ink-muted">
                    {{ __('This body and everything beneath it is resolved from a materialised path, so a consolidated view is one query at any depth.') }}
                </p>
                <code class="mt-2 block rounded bg-surface-sunk px-2 py-1 font-mono text-xs">{{ $body->path }}</code>
                <p class="mt-2 text-xs text-ink-muted">
                    {{ trans_choice('{1}Depth :depth · 1 body in subtree|[2,*]Depth :depth · :count bodies in subtree', count($body->subtreeIds()), ['depth' => $body->depth, 'count' => count($body->subtreeIds())]) }}
                </p>
            </x-card>

            <x-card :title="__('Danger zone')">
                <p class="text-xs text-ink-muted">
                    {{ __('A body that holds institutions or other bodies cannot be deleted — nothing is deleted where money is concerned. Deactivate it instead, from Edit.') }}
                </p>
                <form method="POST" action="{{ route('admin.bodies.destroy', $body) }}" class="mt-3"
                      data-confirm="{{ __('Delete :name? This cannot be undone.', ['name' => $body->name]) }}">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="rounded border border-rose-300 px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-50">
                        {{ __('Delete body') }}
                    </button>
                </form>
            </x-card>
        </div>
    </div>

@endsection
