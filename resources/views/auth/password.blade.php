@extends('layouts.console')

@section('title', $forced ? __('Choose your own password') : __('Change your password'))
@section('subtitle', auth()->user()->email)

@section('content')
    <form method="POST" action="{{ route('account.password.update') }}" class="max-w-md space-y-5 rounded border border-rule bg-surface p-6">
        @csrf

        <p class="text-sm text-ink-soft">
            {{ $forced
                ? __('Your administrator set a first password for you. Choose your own now: from then on, only you know it.')
                : __('Enter your current password, then the new one twice.') }}
        </p>

        @unless ($forced)
            <x-field name="current_password" :label="__('Current password')" required>
                <x-input name="current_password" type="password" required autocomplete="current-password" />
            </x-field>
        @endunless

        <x-field name="password" :label="__('New password')" required :hint="__('At least 10 characters.')">
            <x-input name="password" type="password" required minlength="10" autocomplete="new-password" />
        </x-field>

        <x-field name="password_confirmation" :label="__('New password again')" required>
            <x-input name="password_confirmation" type="password" required minlength="10" autocomplete="new-password" />
        </x-field>

        <button type="submit" class="rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">{{ __('Save my password') }}</button>
    </form>
@endsection
