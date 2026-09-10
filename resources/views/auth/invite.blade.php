<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Set your password') }} — {{ console_name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-surface-sunk px-4 py-10">

<div class="w-full max-w-md">
    <div class="mb-6 text-center">
        <h1 class="text-xl font-semibold text-ink">{{ console_name() }}</h1>
        <p class="mt-1 text-sm text-ink-muted">
            {{ __('Welcome, :name. Choose a password to finish setting up your account.', ['name' => $user->name]) }}
        </p>
    </div>

    <form method="POST" action="{{ route('invite.store', $token) }}"
          class="space-y-4 rounded-xl border border-rule bg-surface p-6 shadow-sm">
        @csrf

        @if ($errors->any())
            <div class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                <ul class="space-y-1">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="rounded bg-surface-sunk px-3 py-2 text-sm">
            <p class="text-ink-muted">{{ __('Signing in as') }}</p>
            <p class="font-medium text-ink">{{ $user->email }}</p>
            @if ($user->body)
                <p class="text-xs text-ink-muted">{{ $user->body->name }}</p>
            @endif
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-ink-soft">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="new-password"
                   class="mt-1 w-full rounded border border-rule-strong px-3 py-2 text-sm">
            <p class="mt-1 text-xs text-ink-muted">
                {{ __('At least 10 characters, with letters and numbers.') }}
            </p>
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-ink-soft">{{ __('Confirm password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded border border-rule-strong px-3 py-2 text-sm">
        </div>

        <button type="submit"
                class="w-full rounded bg-accent px-3 py-2 text-sm font-semibold text-white hover:opacity-90">
            {{ __('Set password and sign in') }}
        </button>

        <p class="text-center text-xs text-ink-muted">
            {{ __('This link works once. It stops working the moment you finish.') }}
        </p>
    </form>
</div>

</body>
</html>
