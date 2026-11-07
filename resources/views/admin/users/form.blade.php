@extends('layouts.console')

@section('title', __('Invite somebody'))
@section('subtitle', __('They set their own password from a single-use link. No password is generated, shown or emailed.'))

@section('content')

    <x-breadcrumb :items="[__('People') => manage_route('users.index'), __('Invite') => null]" />

    <form method="POST" action="{{ manage_route('users.store') }}" class="max-w-2xl space-y-6">
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
            </div>

            @include('admin.users.partials.role-picker')
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
            <a href="{{ manage_route('users.index') }}" class="text-sm text-ink-muted hover:underline">{{ __('Cancel') }}</a>
        </div>
    </form>

@endsection
