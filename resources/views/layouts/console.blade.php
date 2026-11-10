<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full"
      style="--body-accent: {{ \App\Support\BodyContext::accent() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- The brand is configuration, never a literal: this console will outgrow
         a payments name once it carries hospitals and shops (brief §16). --}}
    <title>@yield('title', __('Overview')) — {{ console_name() }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full antialiased">

@php
    // chromeBody(), not currentBody(): the operator belongs to no body and must
    // wear the platform's identity, not that of whichever body sorts first.
    $body = \App\Support\BodyContext::chromeBody();
    $isOperator = \App\Support\BodyContext::isSuperAdmin();
    $impersonating = \App\Support\BodyContext::impersonatedBody();
@endphp

{{-- Viewing as another body.

     Deliberately loud, fixed to the top, and above the sidebar rather than
     inside the page: the failure this prevents is an operator forgetting which
     hat they are wearing and reading one body's shortfall as the group's. An
     amber strip that cannot be scrolled away is the cheapest possible guard
     against that, and the way out is in it. --}}
@if ($impersonating)
    <div class="no-print sticky top-0 z-50 flex flex-wrap items-center justify-between gap-3 bg-amber-400 px-4 py-2 text-sm text-amber-950">
        <p class="font-medium">
            {{ __('Viewing as :body — read-only. You are signed in as :user.', [
                'body' => $impersonating->name,
                'user' => auth()->user()->email,
            ]) }}
        </p>
        <form method="POST" action="{{ route('impersonate.stop') }}">
            @csrf
            <button type="submit"
                    class="rounded bg-amber-950 px-3 py-1 text-xs font-semibold text-amber-50 hover:bg-amber-900">
                {{ __('Leave this view') }}
            </button>
        </form>
    </div>
@endif

<div class="flex min-h-full">

    {{-- ══════════════════════════════════════════════════════════════
         Sidebar, carrying the body's own colour.
         ══════════════════════════════════════════════════════════════ --}}
    <aside class="no-print hidden w-64 shrink-0 flex-col bg-accent lg:flex">

        {{-- The body's crest, where a product logo would normally sit.
             An archdiocese's people know their crest, and a console wearing it
             reads as their record rather than as somebody's software. Falls
             back to a monogram, never to a grey placeholder. --}}
        <div class="flex items-center gap-3 border-b border-white/15 px-5 py-4">
            @if ($body?->logoUrl())
                <img src="{{ $body->logoUrl() }}" alt=""
                     class="h-9 w-9 shrink-0 rounded-sm bg-white/95 object-contain p-0.5">
            @else
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-sm bg-white/15 font-serif text-lg text-white">
                    {{ mb_substr($body?->name ?? platform_name(), 0, 1) }}
                </span>
            @endif

            <div class="min-w-0">
                <p class="truncate font-serif text-[15px] leading-tight text-white">
                    {{ $body?->name ?? platform_name() }}
                </p>
                <p class="truncate text-[11px] uppercase tracking-wider text-white/60">
                    {{ $body?->type ?: ($isOperator ? __('Platform operator') : console_name()) }}
                </p>
            </div>
        </div>

        @include('layouts.partials.console-nav')

        <div class="border-t border-white/15 px-5 py-4 text-xs text-white/60">
            <p class="truncate font-medium text-white/90">{{ auth()->user()->name }}</p>
            <p class="truncate">{{ auth()->user()->email }}</p>
            <div class="mt-2 flex items-center gap-3">
                <a href="{{ route('account.password') }}" class="underline-offset-2 hover:text-white hover:underline">{{ __('Change password') }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="underline-offset-2 hover:text-white hover:underline">
                        {{ __('Sign out') }}
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">

        {{-- The sidebar is hidden below the large breakpoint, and without this a
             phone had no navigation at all. --}}
        <details class="no-print bg-accent text-white lg:hidden">
            <summary class="flex cursor-pointer items-center justify-between px-4 py-3 text-sm font-medium">
                <span class="truncate">{{ $body?->name ?? platform_name() }}</span>
                <span class="ml-3 shrink-0 text-xs text-white/70">{{ __('Menu') }}</span>
            </summary>
            @include('layouts.partials.console-nav')
            <form method="POST" action="{{ route('logout') }}" class="border-t border-white/15 px-5 py-3 text-xs">
                @csrf
                <button type="submit" class="underline-offset-2 hover:underline">{{ __('Sign out') }}</button>
            </form>
        </details>

        {{-- Header. The accent rule beneath the title is the only place a body's
             colour touches the content area — enough to make the page theirs,
             not enough to colour the figures. --}}
        <header class="border-b border-rule bg-surface px-6 pt-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="truncate font-serif text-2xl leading-tight text-ink">@yield('title', __('Overview'))</h1>
                    @hasSection('subtitle')
                        <p class="mt-1 text-sm text-ink-soft">@yield('subtitle')</p>
                    @endif
                </div>

                <div class="no-print flex flex-wrap items-center gap-2">@yield('header-actions')</div>
            </div>

            <div class="mt-4 h-1 w-16 rounded-full bg-accent"></div>
        </header>

        <main class="flex-1 px-6 py-6">
            {{-- Flash messages.

                 Class names are written out in full rather than interpolated.
                 Tailwind v4 scans source files for literal class strings, so
                 "border-{{ $colour }}-200" produces no CSS at all — the markup
                 looks right and the styling silently does not exist. --}}
            @php
                $flashStyles = [
                    'success' => 'border-emerald-400 bg-emerald-50 text-emerald-900',
                    'error'   => 'border-rose-400 bg-rose-50 text-rose-900',
                    'warning' => 'border-amber-400 bg-amber-50 text-amber-900',
                ];
            @endphp

            @foreach ($flashStyles as $key => $classes)
                @if (session($key))
                    <div class="mb-5 rounded border-l-4 px-4 py-3 text-sm {{ $classes }}">
                        {{ session($key) }}
                    </div>
                @endif
            @endforeach

            @includeWhen(session('secret'), 'admin.partials.secret-once')
            @includeWhen(session('invite'), 'admin.partials.invite-once')

            @yield('content')
        </main>

        <footer class="border-t border-rule px-6 py-4 text-xs text-ink-muted">
            <p class="hidden print:block">{{ __('Printed from :platform by :name on :date.', ['platform' => platform_name(), 'name' => auth()->user()?->name, 'date' => now()->translatedFormat('j F Y, H:i')]) }}</p>
            {{ __(':platform receives figures; it never holds funds and is never the authority on an institution\'s books.', ['platform' => platform_name()]) }}
        </footer>
    </div>
</div>

@stack('scripts')
</body>
</html>
