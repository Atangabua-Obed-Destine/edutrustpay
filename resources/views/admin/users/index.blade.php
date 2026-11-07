@extends('layouts.console')

@section('title', __('People'))
@section('subtitle', __('Everyone with access. Accounts are created by invitation — no password is ever generated or sent.'))

@section('header-actions')
    @can('user.invite')
        <a href="{{ manage_route('users.create') }}"
           class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('Invite somebody') }}
        </a>
    @endcan
@endsection

@section('content')

    <form method="GET" class="mb-4">
        <label for="body" class="mr-2 text-sm text-ink-muted">{{ __('Body') }}</label>
        <select name="body" id="body" onchange="this.form.submit()"
                class="rounded border-rule-strong py-1.5 text-sm">
            <option value="">{{ __('All bodies') }}</option>
            @foreach ($bodies as $b)
                <option value="{{ $b->id }}" @selected((string) $filters['body'] === (string) $b->id)>{{ $b->indented_name }}</option>
            @endforeach
        </select>
    </form>

    @if ($users->isEmpty())
        <div class="rounded border border-dashed border-rule-strong bg-surface p-8 text-center">
            <p class="text-sm font-medium text-ink">{{ __('Nobody invited yet') }}</p>
            <p class="mx-auto mt-1 max-w-lg text-sm text-ink-muted">
                {{ __('A body with no users has nobody to see its findings. Start with the bursary head.') }}
            </p>
        </div>
    @else
        <div class="overflow-x-auto rounded border border-rule">
            <table class="min-w-full divide-y divide-rule text-sm">
                <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('Name') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Body') }}</th>
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
                            <td class="px-4 py-2 text-ink-muted">
                                {{ $user->body?->name ?? __('— platform operator —') }}
                                @if ($user->institution)
                                    <span class="block text-xs">{{ __('liaison for :name', ['name' => $user->institution->name]) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-ink-muted">
                                {{ $user->roles->pluck('display_name')->implode(', ') ?: $user->role }}
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
            {{ __('Users are never deleted: somebody who resolved a finding has their name against it, and removing the account would leave that decision unattributed.') }}
        </p>
    @endif

@endsection
