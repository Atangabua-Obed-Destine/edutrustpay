@extends('layouts.console')

@section('title', __('Roles'))
@section('subtitle', __('What each person here may do. Start from a standard role, or make your own.'))

@section('header-actions')
    @can('role.manage')
        <a href="{{ route('body.manage.roles.create') }}"
           class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
            {{ __('New role') }}
        </a>
    @endcan
@endsection

@section('content')

    <div class="overflow-x-auto rounded border border-rule">
        <table class="min-w-full divide-y divide-rule text-sm">
            <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    <th class="px-4 py-2 font-semibold">{{ __('Role') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-right font-semibold">{{ __('People') }}</th>
                    <th class="px-4 py-2 text-right font-semibold">{{ __('Permissions') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule bg-surface">
                @foreach ($roles as $role)
                    <tr>
                        <td class="px-4 py-2">
                            <span class="font-medium text-ink">{{ $role->display_name }}</span>
                            <span class="block text-xs text-ink-muted">{{ $role->description }}</span>
                        </td>
                        <td class="px-4 py-2 text-ink-muted">{{ $role->is_system ? __('Standard') : __('Your own') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $role->users_count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums" title="{{ $role->permissions->pluck('display_name')->implode(', ') }}">{{ $role->permissions->count() }}</td>
                        <td class="px-4 py-2">
                            @can('role.manage')
                                <div class="flex justify-end gap-3">
                                    <a href="{{ route('body.manage.roles.create', ['from' => $role->id]) }}" class="text-xs text-ink-soft hover:underline">{{ __('Copy') }}</a>
                                    @if (in_array($role->id, $manageableIds, true))
                                        <a href="{{ route('body.manage.roles.edit', $role) }}" class="text-xs text-ink-soft hover:underline">{{ __('Edit') }}</a>
                                        <form method="POST" action="{{ route('body.manage.roles.destroy', $role) }}"
                                              data-confirm="{{ __('Delete :name?', ['name' => $role->display_name]) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs text-rose-600 hover:underline">{{ __('Delete') }}</button>
                                        </form>
                                    @endif
                                </div>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
