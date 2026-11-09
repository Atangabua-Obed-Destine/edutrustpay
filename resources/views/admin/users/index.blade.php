@extends('layouts.console')

@section('title', __('People'))
@section('subtitle', __('Everyone who can sign in. You invite people with a link, and they choose their own password.'))

@php
    // The operator always sees which body each person is in. A body's own
    // administrator only does when the body has parts, such as deaneries.
    $showPart = request()->routeIs('admin.*') || $bodies->count() > 1;
    $partWord = request()->routeIs('admin.*') ? __('Body') : __('Part of');
@endphp

@section('header-actions')
    @can('user.invite')
        <a href="{{ manage_route('users.create') }}"
           class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('Invite somebody') }}
        </a>
    @endcan
@endsection

@section('content')

    @if ($showPart)
    <form method="GET" class="mb-4">
        <label for="body" class="mr-2 text-sm text-ink-muted">{{ $partWord }}</label>
        <select name="body" id="body" onchange="this.form.submit()"
                class="rounded border-rule-strong py-1.5 text-sm">
            <option value="">{{ request()->routeIs('admin.*') ? __('All bodies') : __('All of them') }}</option>
            @foreach ($bodies as $b)
                <option value="{{ $b->id }}" @selected((string) $filters['body'] === (string) $b->id)>{{ $b->indented_name }}</option>
            @endforeach
        </select>
    </form>
    @endif

    @if ($users->isEmpty())
        <div class="rounded border border-dashed border-rule-strong bg-surface p-8 text-center">
            <p class="text-sm font-medium text-ink">{{ __('Nobody invited yet') }}</p>
            <p class="mx-auto mt-1 max-w-lg text-sm text-ink-muted">
                {{ __('With nobody invited, nobody sees what needs looking into. Start with the bursary head.') }}
            </p>
        </div>
    @else
        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('Name') }}</th>
                        @if ($showPart)
                            <th class="px-4 py-2 font-semibold">{{ $partWord }}</th>
                        @endif
                        <th class="px-4 py-2 font-semibold">{{ __('Role') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-rule bg-surface">
                    @foreach ($users as $user)
                        <tr>
                            <td class="px-4 py-2">
                                <span class="font-medium text-ink">{{ $user->name }}</span>
                                <span class="block text-xs text-ink-muted">{{ $user->email }}</span>
                            </td>
                            @if ($showPart)
                                <td class="px-4 py-2 text-ink-muted">{{ $user->body?->name ?? __('— platform operator —') }}</td>
                            @endif
                            <td class="px-4 py-2 text-ink-muted">
                                {{ $user->roles->pluck('display_name')->implode(', ') ?: $user->role }}
                                @if ($user->institution)
                                    <span class="block text-xs">{{ __('for :name only', ['name' => $user->institution->name]) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                @if (! $user->is_active)
                                    <span class="rounded bg-surface-sunk px-1.5 py-0.5 text-xs text-ink-soft">{{ __('deactivated') }}</span>
                                @elseif ($user->invite_accepted_at)
                                    <span class="text-xs text-emerald-700">{{ __('active') }}</span>
                                @else
                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">
                                        {{ __('invitation pending') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex justify-end gap-3">
                                    @if ($user->id !== auth()->id() && auth()->user()->can('user.update') && ! $user->isSuperAdmin())
                                        <a href="{{ manage_route('users.edit', $user) }}" class="text-xs text-ink-soft hover:underline">{{ __('Change roles') }}</a>
                                    @endif

                                    @if (! $user->invite_accepted_at && auth()->user()->can('user.invite'))
                                        <form method="POST" action="{{ manage_route('users.reinvite', $user) }}">
                                            @csrf
                                            <button type="submit" class="text-xs text-ink-soft hover:underline">{{ __('New link') }}</button>
                                        </form>
                                    @endif

                                    @if ($user->id !== auth()->id() && auth()->user()->can('user.deactivate'))
                                        <form method="POST" action="{{ manage_route('users.toggle', $user) }}"
                                              data-confirm="{{ $user->is_active
                                                  ? __('Deactivate :name? They will not be able to sign in.', ['name' => $user->name])
                                                  : __('Reactivate :name?', ['name' => $user->name]) }}">
                                            @csrf
                                            <button type="submit" @class([
                                                'text-xs hover:underline',
                                                'text-rose-600' => $user->is_active,
                                                'text-emerald-700' => ! $user->is_active,
                                            ])>{{ $user->is_active ? __('Deactivate') : __('Reactivate') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-ink-muted">
            {{ __('Nobody is ever deleted, only deactivated. Whoever answered something under To look into keeps their name on it.') }}
        </p>
    @endif

@endsection
