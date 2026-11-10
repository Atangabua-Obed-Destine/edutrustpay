@extends('install.layout')

{{-- Every error page says what happened in a plain sentence and offers a way back, never a bare code. --}}
@section('content')
    <div class="rounded-xl border border-rule bg-surface p-6 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-ink-muted">{{ $code }}</p>
        <h1 class="mt-1 font-serif text-2xl text-ink">{{ $heading }}</h1>
        <p class="mt-2 text-sm text-ink-soft">{{ $text }}</p>
        <div class="mt-6 flex flex-wrap gap-3 text-sm">
            @auth
                <a href="{{ url('/') }}" class="rounded bg-accent px-4 py-2 font-semibold text-white hover:opacity-90">{{ __('Back to the Overview') }}</a>
            @else
                <a href="{{ route('login') }}" class="rounded bg-accent px-4 py-2 font-semibold text-white hover:opacity-90">{{ __('Sign in') }}</a>
            @endauth
            <a href="javascript:history.back()" class="rounded border border-rule-strong px-4 py-2 font-medium text-ink hover:bg-surface-sunk">{{ __('Go back') }}</a>
        </div>
    </div>
@endsection
