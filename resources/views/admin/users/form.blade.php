@extends('layouts.console')

@section('title', __('Invite somebody'))
@section('subtitle', __('They set their own password from a single-use link. No password is generated, shown or emailed.'))

@section('content')

    <x-breadcrumb :items="[__('People') => route('admin.users.index'), __('Invite') => null]" />

    <form method="POST" action="{{ route('admin.users.store') }}" class="max-w-2xl space-y-6">
        @csrf

        <x-card :title="__('Who')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="name" :label="__('Full name')" required>
                    <x-input name="name" :value="$user->name" required autofocus />
                </x-field>

                <x-field name="email" :label="__('Email')" required>
                    <x-input name="email" type="email" :value="$user->email" required />
                </x-field>

                <x-field name="body_id" :label="__('Body')" required
                         :hint="__('They will see this body and everything nested beneath it.')">
                    <x-select name="body_id" :selected="$user->body_id" :placeholder="__('— choose a body —')"
                              :options="$bodies->mapWithKeys(fn ($b) => [$b->id => $b->indented_name])->all()" />
                </x-field>

                <x-field name="role_id" :label="__('Role')" required>
                    <x-select name="role_id" :selected="null" :placeholder="__('— choose a role —')"
                              :options="$roles->mapWithKeys(fn ($r) => [$r->id => $r->display_name])->all()" />
                </x-field>
            </div>

            <div class="mt-4 rounded border border-rule bg-surface-sunk p-3 text-xs text-ink-soft">
                <ul class="space-y-1">
                    @foreach ($roles as $role)
                        <li><span class="font-semibold">{{ $role->display_name }}</span> — {{ $role->description }}</li>
                    @endforeach
                </ul>
            </div>
        </x-card>

        <x-card :title="__('Institution liaison only')"
                :subtitle="__('Leave empty unless the role above is Institution Liaison — a liaison sees exactly one institution and nothing else in the body.')">
            <x-field name="institution_id" :label="__('Institution')">
                <x-select name="institution_id" :selected="$user->institution_id" :placeholder="__('— not a liaison —')"
                          :options="$institutions->mapWithKeys(fn ($i) => [$i->id => $i->name . ' (' . $i->body->name . ')'])->all()" />
            </x-field>
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                {{ __('Create invitation') }}
            </button>
            <a href="{{ route('admin.users.index') }}" class="text-sm text-ink-muted hover:underline">{{ __('Cancel') }}</a>
        </div>
    </form>

@endsection
