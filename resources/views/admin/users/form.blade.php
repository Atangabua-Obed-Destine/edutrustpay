@extends('layouts.console')

@section('title', __('Invite somebody'))
@section('subtitle', __('You get a link to give them. They open it once and choose their own password; nobody else ever sees it.'))

@php
    $forOperator = request()->routeIs('admin.*');
    $oneBody = ! $forOperator && $bodies->count() === 1;
@endphp

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

                @if ($forOperator)
                    <x-field name="body_id" :label="__('Body')" required
                             :hint="__('They will see this body and everything nested beneath it.')">
                        <x-select name="body_id" :selected="$user->body_id" :placeholder="__('— choose a body —')"
                                  :options="$bodies->mapWithKeys(fn ($b) => [$b->id => $b->indented_name])->all()" />
                    </x-field>
                @elseif ($oneBody)
                    <input type="hidden" name="body_id" value="{{ $bodies->first()->id }}">
                @else
                    <x-field name="body_id" :label="__('Looks after')" required
                             :hint="__('They see the part you choose and everything inside it.')">
                        <x-select name="body_id" :selected="$user->body_id ?? $bodies->first()->id"
                                  :options="$bodies->mapWithKeys(fn ($b, $i) => [$b->id => $i === 0 ? __(':name (all of it)', ['name' => $b->name]) : $b->indented_name])->all()" />
                    </x-field>
                @endif
            </div>

            @include('admin.users.partials.role-picker')
        </x-card>

        <x-card :title="__('Only for an Institution Liaison')"
                :subtitle="__('A liaison sees one institution and nothing else. Choose it here; leave this empty for everyone else.')">
            <x-field name="institution_id" :label="__('Institution')">
                <x-select name="institution_id" :selected="$user->institution_id" :placeholder="__('— not a liaison —')"
                          :options="$institutions->mapWithKeys(fn ($i) => [$i->id => $oneBody ? $i->name : $i->name . ' (' . $i->body->name . ')'])->all()" />
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
