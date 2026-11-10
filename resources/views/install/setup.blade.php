@extends('install.layout')

@php
    $input = 'mt-1 w-full rounded border border-rule-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent';
@endphp

@section('content')
    <h1 class="font-serif text-2xl text-ink">{{ __('Welcome to :platform', ['platform' => platform_name()]) }}</h1>
    <p class="mt-1 text-sm text-ink-soft">{{ __('Two things to start: the operator account that runs this console, and the first organisation it oversees.') }}</p>

    {{-- What the console is, before anyone is asked for anything. --}}
    <ol class="mt-6 grid gap-3 sm:grid-cols-3">
        @foreach ([
            [__('Schools report by themselves'), __('Each school\'s accounting system sends its monthly figures here, signed. Nobody types them in.')],
            [__('Each organisation sees its own'), __('A diocese or other organisation sees its schools, checked against its rules.')],
            [__('Nothing here changes the books'), __('The console only reads what schools send. Their books stay in their own systems.')],
        ] as $i => [$title, $text])
            <li class="flex gap-3 rounded-xl border border-rule bg-surface p-4">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-semibold text-white">{{ $i + 1 }}</span>
                <span class="text-sm">
                    <span class="block font-medium text-ink">{{ $title }}</span>
                    <span class="mt-0.5 block text-ink-soft">{{ $text }}</span>
                </span>
            </li>
        @endforeach
    </ol>

    @if ($errors->any())
        <div class="mt-6 rounded border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('install.store', $token) }}" class="mt-6 space-y-6">
        @csrf

        <section class="rounded-xl border border-rule bg-surface p-6 shadow-sm">
            <h2 class="font-serif text-lg text-ink">{{ __('The operator') }}</h2>
            <p class="mt-1 text-sm text-ink-soft">{{ __('This account sees every organisation and creates all the others. Keep it for the people who run the console.') }}</p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="block text-sm font-medium text-ink-soft">{{ __('Full name') }}</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus class="{{ $input }}">
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-ink-soft">{{ __('Email') }}</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" class="{{ $input }}">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-ink-soft">{{ __('Password') }}</label>
                    <input id="password" type="password" name="password" minlength="10" required autocomplete="new-password" class="{{ $input }}">
                    <p class="mt-1 text-xs text-ink-muted">{{ __('At least 10 characters. This account can see every organisation\'s money.') }}</p>
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-ink-soft">{{ __('Confirm password') }}</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" minlength="10" required autocomplete="new-password" class="{{ $input }}">
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-rule bg-surface p-6 shadow-sm">
            <h2 class="font-serif text-lg text-ink">{{ __('The first organisation') }} <span class="font-sans text-sm text-ink-muted">({{ __('optional') }})</span></h2>
            <p class="mt-1 text-sm text-ink-soft">{{ __('The diocese or other organisation whose schools will report here. You can leave it for now and add it, and others, later under Bodies.') }}</p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="organisation_name" class="block text-sm font-medium text-ink-soft">{{ __('Name') }}</label>
                    <input id="organisation_name" type="text" name="organisation_name" value="{{ old('organisation_name') }}"
                           placeholder="{{ __('e.g. Archdiocese of Bamenda') }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="organisation_type" class="block text-sm font-medium text-ink-soft">{{ __('What kind of organisation') }}</label>
                    <input id="organisation_type" type="text" name="organisation_type" value="{{ old('organisation_type') }}" list="organisation-types"
                           placeholder="{{ __('e.g. Archdiocese') }}" class="{{ $input }}">
                    <datalist id="organisation-types">
                        @foreach ([__('Archdiocese'), __('Diocese'), __('Congregation'), __('School group'), __('Regional Delegation'), __('Ministry'), __('Trust')] as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>
                </div>
            </div>
        </section>

        <button type="submit" class="w-full rounded-xl bg-accent px-4 py-3 text-sm font-semibold text-white shadow-sm hover:opacity-90">
            {{ __('Finish setting up') }}
        </button>
    </form>
@endsection
