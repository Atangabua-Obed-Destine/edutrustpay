<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Sign in') }} — {{ console_name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-surface-sunk px-4">

<div class="w-full max-w-sm">
    <div class="mb-6 text-center">
        <h1 class="text-xl font-semibold text-ink">{{ console_name() }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('Consolidated oversight for the body.') }}</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}"
          class="space-y-4 rounded-xl border border-rule bg-surface p-6 shadow-sm">
        @csrf

        @if ($errors->any())
            <div class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800
                       ">
                {{ $errors->first() }}
            </div>
        @endif

        <div>
            <label for="email" class="block text-sm font-medium text-ink-soft">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"
                   class="mt-1 w-full rounded border-rule-strong bg-surface px-3 py-2 text-sm">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-ink-soft">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" required
                   class="mt-1 w-full rounded border-rule-strong bg-surface px-3 py-2 text-sm">
        </div>

        <label class="flex items-center gap-2 text-sm text-ink-soft">
            <input type="checkbox" name="remember" value="1" class="rounded border-rule-strong">
            {{ __('Stay signed in') }}
        </label>

        <button type="submit"
                class="w-full rounded bg-accent px-3 py-2 text-sm font-semibold text-white hover:opacity-90">
            {{ __('Sign in') }}
        </button>
    </form>
</div>

</body>
</html>
