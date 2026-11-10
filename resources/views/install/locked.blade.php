@extends('install.layout')

@section('content')
    <div class="rounded-xl border border-rule bg-surface p-6 shadow-sm">
        <h1 class="font-serif text-xl text-ink">{{ __(':platform is not set up on this server yet', ['platform' => platform_name()]) }}</h1>
        <p class="mt-2 text-sm text-ink-soft">{{ __('Setting it up needs the private link the installer gives. On the server, in the console\'s folder, run:') }}</p>
        <pre class="mt-4 overflow-x-auto rounded-lg bg-[#0f1f35] p-3 text-sm text-white">php artisan edutrustpay:install</pre>
        <p class="mt-3 text-xs text-ink-muted">{{ __('The link works once. Running the command again gives a new one.') }}</p>
    </div>
@endsection
