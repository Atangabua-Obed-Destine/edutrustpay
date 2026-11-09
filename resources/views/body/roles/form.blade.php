@extends('layouts.console')

@section('title', $role->exists ? __('Edit :name', ['name' => $role->display_name]) : __('New role'))
@section('subtitle', __('You can only grant permissions you hold yourself.'))

@section('content')

    <x-breadcrumb :items="[__('Roles') => route('body.manage.roles.index'), ($role->exists ? $role->display_name : __('New')) => null]" />

    <form method="POST" action="{{ $role->exists ? route('body.manage.roles.update', $role) : route('body.manage.roles.store') }}"
          class="max-w-3xl space-y-6">
        @csrf
        @if ($role->exists) @method('PUT') @endif

        <x-card :title="__('Role')">
            <div class="grid gap-4">
                <x-field name="display_name" :label="__('Name')" required>
                    <x-input name="display_name" :value="$role->display_name" required autofocus placeholder="{{ __('e.g. Deputy Education Secretary') }}" />
                </x-field>
                <x-field name="description" :label="__('What this person does')">
                    <x-input name="description" :value="$role->description" />
                </x-field>
            </div>
        </x-card>

        <x-card :title="__('Permissions')">
            @error('permissions')
                <p class="mb-3 text-xs font-medium text-rose-700">{{ $message }}</p>
            @enderror

            <div class="grid gap-5 sm:grid-cols-2">
                @foreach ($groups as $group => $permissions)
                    <fieldset>
                        @php
                            $headings = [
                                'Body' => __('This console'), 'Institution' => __('Institutions'), 'Credential' => __('Connection keys'),
                                'Capability' => __('What systems send'), 'Report' => __('Monthly reports'), 'Finding' => __('To look into'),
                                'Consolidation' => __('Everything added up'), 'User' => __('People'), 'Role' => __('Roles'),
                                'Compliance' => __('Rules'), 'Daybook' => __('Daybooks'), 'Audit' => __('Record of who did what'), 'Settings' => __('Settings'),
                            ];
                        @endphp
                        <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ $headings[$group] ?? __($group) }}</legend>
                        @foreach ($permissions as $permission)
                            <label class="flex items-center gap-2 py-0.5 text-sm text-ink-soft">
                                <input type="checkbox" name="permissions[]" value="{{ $permission->name }}"
                                       class="rounded border-rule-strong"
                                       @checked(in_array($permission->name, old('permissions', $selected), true))>
                                {{ __($permission->display_name) }}
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
            </div>
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                {{ $role->exists ? __('Save changes') : __('Create role') }}
            </button>
            <a href="{{ route('body.manage.roles.index') }}" class="text-sm text-ink-muted hover:underline">{{ __('Cancel') }}</a>
        </div>
    </form>

@endsection
