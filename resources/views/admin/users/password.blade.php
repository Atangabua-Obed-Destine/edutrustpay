@extends('layouts.console')

@section('title', __('Set a new password for :name', ['name' => $user->name]))
@section('subtitle', $user->email)

@section('content')

    <x-breadcrumb :items="[__('People') => manage_route('users.index'), $user->name => null]" />

    <form method="POST" action="{{ manage_route('users.password.update', $user) }}" class="max-w-md space-y-5 rounded border border-rule bg-surface p-6">
        @csrf

        <p class="text-sm text-ink-soft">{{ __('For someone who has forgotten their password, or whose invitation link was lost. Give them the new password in person or by phone, never by a message others can read.') }}</p>

        <x-field name="password" :label="__('New password')" required :hint="__('At least 10 characters.')">
            <x-input name="password" type="password" required minlength="10" autocomplete="new-password" />
        </x-field>

        <x-field name="password_confirmation" :label="__('The same password again')" required>
            <x-input name="password_confirmation" type="password" required minlength="10" autocomplete="new-password" />
        </x-field>

        <label class="flex items-center gap-2 text-sm text-ink-soft">
            <input type="hidden" name="must_change" value="0">
            <input type="checkbox" name="must_change" value="1" checked class="rounded border-rule-strong">
            {{ __('Ask them to choose their own password when they next sign in (recommended)') }}
        </label>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">{{ __('Set password') }}</button>
            <a href="{{ manage_route('users.index') }}" class="text-sm text-ink-muted hover:underline">{{ __('Cancel') }}</a>
        </div>
    </form>

@endsection
