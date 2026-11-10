<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ __('Welcome to :platform', ['platform' => platform_name()]) }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-surface-sunk antialiased">
    <header class="text-white" style="background: linear-gradient(160deg, #0f1f35 0%, #1f3a5f 50%, #2b5283 100%);">
        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
            <p class="font-serif text-3xl leading-tight">{{ platform_name() }}</p>
            <p class="mt-1 text-sm text-white/75">{{ __('powered by') }} <span class="font-semibold tracking-wide text-white">I-NNOVA</span></p>
            <p class="mt-4 max-w-xl text-sm text-white/80">{{ __('Oversight for the organisations that run schools: every school\'s monthly figures, checked against your rules, in one place.') }}</p>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-3xl px-4 pb-10 text-xs text-ink-muted sm:px-6">
        {{ __(':platform receives figures; it never holds funds and is never the authority on an institution\'s books.', ['platform' => platform_name()]) }}
    </footer>
</body>
</html>
