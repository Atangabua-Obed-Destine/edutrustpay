@extends('layouts.console')

@section('title', __('Add a person'))
@section('subtitle', __('Their account is ready as soon as you save. They sign in with their email and the password you set.'))

@php
    $forOperator = request()->routeIs('admin.*');
    $oneBody = ! $forOperator && $bodies->count() === 1;
@endphp

@section('content')

    <x-breadcrumb :items="[__('People') => manage_route('users.index'), __('Add a person') => null]" />

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

        {{-- How they get in: a first password set here (the usual way), or a link they open to set their own. --}}
        @php $how = old('how', 'password'); @endphp
        <x-card :title="__('How they sign in')">
            <div class="space-y-3 text-sm">
                <label class="flex items-start gap-2">
                    <input type="radio" name="how" value="password" @checked($how === 'password') class="mt-1" onchange="document.getElementById('first-password').hidden = false">
                    <span>
                        <span class="font-medium text-ink">{{ __('Set a first password now') }}</span>
                        <span class="block text-xs text-ink-muted">{{ __('The account works at once. Give them the password in person or by phone.') }}</span>
                    </span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="radio" name="how" value="link" @checked($how === 'link') class="mt-1" onchange="document.getElementById('first-password').hidden = true">
                    <span>
                        <span class="font-medium text-ink">{{ __('Send them a link instead') }}</span>
                        <span class="block text-xs text-ink-muted">{{ __('You get a one-time link to send them; they open it and choose their own password. It works for 14 days.') }}</span>
                    </span>
                </label>
            </div>

            <div id="first-password" class="mt-4 grid gap-4 border-t border-rule pt-4 sm:grid-cols-2" @if ($how === 'link') hidden @endif>
                <x-field name="password" :label="__('First password')" :hint="__('At least 10 characters.')">
                    <x-input name="password" type="password" minlength="10" autocomplete="new-password" />
                </x-field>
                <x-field name="password_confirmation" :label="__('The same password again')">
                    <x-input name="password_confirmation" type="password" minlength="10" autocomplete="new-password" />
                </x-field>
                <label class="flex items-center gap-2 text-sm text-ink-soft sm:col-span-2">
                    <input type="hidden" name="must_change" value="0">
                    <input type="checkbox" name="must_change" value="1" @checked(old('must_change', '1') === '1') class="rounded border-rule-strong">
                    {{ __('Ask them to choose their own password the first time they sign in (recommended)') }}
                </label>
            </div>
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                {{ __('Save') }}
            </button>
            <a href="{{ manage_route('users.index') }}" class="text-sm text-ink-muted hover:underline">{{ __('Cancel') }}</a>
        </div>
    </form>

@endsection
