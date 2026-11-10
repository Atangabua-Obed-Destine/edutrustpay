<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Sign in') }} — {{ platform_name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Ruled like a ledger page, faintly: the console is a book of other people's books. */
        .ledger-rule {
            background-image:
                linear-gradient(to bottom, rgba(255, 255, 255, 0.045) 1px, transparent 1px),
                linear-gradient(to right, rgba(232, 181, 74, 0.10) 1px, transparent 1px);
            background-size: 100% 34px, 100% 100%;
            background-position: 0 0, 72px 0;
            background-repeat: repeat, no-repeat;
        }
        .glow { filter: blur(70px); }
        @keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        .rise { animation: rise .7s ease-out both; }
        .rise-2 { animation-delay: .12s; }
        .rise-3 { animation-delay: .24s; }
        /* On a short screen the drawing gives way, so the points still fit beside the form. */
        @media (min-width: 1024px) and (max-height: 900px) { .tall-only { display: none !important; } }
        @media (prefers-reduced-motion: reduce) {
            .rise { animation: none; }
            .flow-dot { display: none; }
        }
    </style>
</head>
<body class="h-full bg-surface-sunk antialiased">

<div class="flex min-h-full flex-col lg:grid lg:grid-cols-[minmax(0,1.08fr)_minmax(0,1fr)]">

    {{-- ═════════════ The platform: what it is, before anyone is asked for anything. ═════════════ --}}
    <aside class="relative overflow-hidden text-white lg:sticky lg:top-0 lg:h-screen"
           style="background-color: #0b1a2e; background-image: linear-gradient(155deg, #0b1a2e 0%, #13294a 45%, #1f3a5f 100%);">
        <div class="ledger-rule absolute inset-0" aria-hidden="true"></div>
        <div class="glow absolute -right-24 -top-24 h-80 w-80 rounded-full bg-[#e8b54a]/20" aria-hidden="true"></div>
        <div class="glow absolute -bottom-32 -left-20 h-96 w-96 rounded-full bg-[#3b6fb0]/30" aria-hidden="true"></div>

        <div class="relative mx-auto flex h-full max-w-xl flex-col px-6 py-8 sm:px-10 lg:py-12">
            <div class="rise flex items-center gap-3">
                <x-brand-mark class="h-11 w-11 shrink-0" />
                <div class="leading-tight">
                    <p class="font-serif text-xl">{{ platform_name() }}</p>
                    <p class="text-xs text-white/65">{{ __('powered by') }} <span class="font-semibold tracking-[0.18em] text-[#e8b54a]">I-NNOVA</span></p>
                </div>
            </div>

            <div class="rise rise-2 mt-8 lg:mt-12">
                <h2 class="font-serif text-3xl leading-tight sm:text-4xl">
                    {{ __('Every school\'s figures.') }}<br>
                    <span class="text-[#e8b54a]">{{ __('One honest picture.') }}</span>
                </h2>
                <p class="mt-3 max-w-md text-sm leading-relaxed text-white/75 sm:text-[15px]">
                    {{ __('The oversight console for dioceses and the organisations that run schools. Each school\'s own system reports here every month, and you see them all together.') }}
                </p>
            </div>

            {{-- Reports leaving each school's system and arriving, signed, at the console. --}}
            <div class="tall-only rise rise-3 mt-8 hidden sm:block" aria-hidden="true">
                <svg viewBox="0 0 460 210" class="w-full max-w-md">
                    <defs>
                        <linearGradient id="flow-line" x1="0" x2="1">
                            <stop offset="0" stop-color="#ffffff" stop-opacity="0.08"/>
                            <stop offset="1" stop-color="#e8b54a" stop-opacity="0.55"/>
                        </linearGradient>
                        <radialGradient id="hub-glow">
                            <stop offset="0" stop-color="#e8b54a" stop-opacity="0.35"/>
                            <stop offset="1" stop-color="#e8b54a" stop-opacity="0"/>
                        </radialGradient>
                    </defs>

                    @php $schools = [[38, 22], [38, 64], [38, 106], [38, 148], [38, 190]]; @endphp
                    @foreach ($schools as $i => [$x, $y])
                        <path id="flow-{{ $i }}" d="M{{ $x + 24 }} {{ $y }} C 190 {{ $y }}, 230 105, 352 105"
                              fill="none" stroke="url(#flow-line)" stroke-width="1.4" stroke-dasharray="3 5"/>
                    @endforeach

                    @foreach ($schools as $i => [$x, $y])
                        <g transform="translate({{ $x }} {{ $y }})">
                            <rect x="-20" y="-16" width="40" height="32" rx="8" fill="#ffffff" fill-opacity="0.07" stroke="#ffffff" stroke-opacity="0.22"/>
                            <path d="M-9 -2 L0 -9 L9 -2 Z" fill="none" stroke="#ffffff" stroke-opacity="0.85" stroke-width="1.4" stroke-linejoin="round"/>
                            <path d="M-7 -2 V8 H7 V-2 M-2 8 V3 H2 V8" fill="none" stroke="#ffffff" stroke-opacity="0.85" stroke-width="1.4" stroke-linejoin="round"/>
                        </g>
                        <circle class="flow-dot" r="3.2" fill="#e8b54a" opacity="0">
                            <animateMotion dur="3.6s" begin="{{ $i * 0.7 }}s" repeatCount="indefinite" keyPoints="0;1" keyTimes="0;1" calcMode="linear">
                                <mpath href="#flow-{{ $i }}"/>
                            </animateMotion>
                            <animate attributeName="opacity" values="0;1;1;0" keyTimes="0;0.1;0.85;1" dur="3.6s" begin="{{ $i * 0.7 }}s" repeatCount="indefinite"/>
                        </circle>
                    @endforeach

                    <circle cx="390" cy="105" r="62" fill="url(#hub-glow)"/>
                    <circle cx="390" cy="105" r="38" fill="#0b1a2e" stroke="#e8b54a" stroke-opacity="0.7" stroke-width="1.5"/>
                    <path d="M390 82l17 6.3v12.9c0 10.6-7.1 19.1-17 22.9-9.9-3.8-17-12.3-17-22.9V88.3L390 82z"
                          fill="#e8b54a" fill-opacity="0.14" stroke="#e8b54a" stroke-width="1.8" stroke-linejoin="round"/>
                    <path d="M382.4 104.3l5.4 5.4 10.6-11.2" fill="none" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>

            <ul class="rise rise-3 mt-8 hidden space-y-3 lg:block">
                @foreach ([
                    ['M12 3l7 2.6v5.3c0 4.4-2.9 7.9-7 9.5-4.1-1.6-7-5.1-7-9.5V5.6L12 3z M9 12l2 2 4-4', __('Signed at the source'), __('Reports come straight from each school\'s accounting system. Nobody types them in again.')],
                    ['M5 6h14 M5 12h14 M5 18h9 M17 16l2 2 3-4', __('Your rules, checked'), __('Fees and rules compared every month, with what is worth asking about set out plainly.')],
                    ['M4.5 5.5h15v13h-15z M9 12h6', __('Missing is never zero'), __('A figure a school did not send says so, and every total says whose figures it holds.')],
                ] as [$icon, $title, $text])
                    <li class="flex gap-4 rounded-2xl border border-white/10 bg-white/[0.06] p-4 backdrop-blur-sm">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#e8b54a] text-[#0b1a2e]">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                        </span>
                        <span>
                            <span class="block text-sm font-semibold">{{ $title }}</span>
                            <span class="mt-0.5 block text-[13px] leading-snug text-white/70">{{ $text }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>

            <p class="mt-auto hidden pt-10 text-xs text-white/45 lg:block">
                {{ __(':platform receives figures; it never holds funds and is never the authority on an institution\'s books.', ['platform' => platform_name()]) }}
            </p>
        </div>
    </aside>

    {{-- ═════════════ Signing in. ═════════════ --}}
    <main class="flex flex-1 items-center justify-center px-4 py-10 sm:px-8"
          style="background: radial-gradient(1100px 560px at 100% 0%, rgba(232, 181, 74, 0.09), transparent 60%), radial-gradient(900px 520px at 0% 100%, rgba(31, 58, 95, 0.07), transparent 60%);">
        <div class="w-full max-w-md">
            <div class="text-center">
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl text-white shadow-lg shadow-[#1f3a5f]/25"
                      style="background: linear-gradient(145deg, #1f3a5f, #0b1a2e);">
                    <svg viewBox="0 0 24 24" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="9" cy="8" r="3.5"/>
                        <path d="M2.5 20c.6-3.6 3.3-6 6.5-6 1.4 0 2.7.4 3.7 1.2"/>
                        <path d="M18 12l3.5 1.3v2.6c0 2.2-1.5 4-3.5 4.8-2-.8-3.5-2.6-3.5-4.8v-2.6L18 12z" stroke="#e8b54a"/>
                    </svg>
                </span>
                <h1 class="mt-5 font-serif text-3xl text-ink">{{ __('Sign in to your console') }}</h1>
                <p class="mt-2 text-sm text-ink-muted">{{ __('For the people who oversee the schools, and those who run the platform.') }}</p>
            </div>

            <form method="POST" action="{{ route('login.store') }}"
                  class="mt-8 space-y-5 rounded-2xl border border-rule bg-surface p-6 shadow-xl shadow-[#0b1a2e]/[0.06] sm:p-8">
                @csrf

                @if ($errors->any())
                    <div class="flex gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-800" role="alert">
                        <svg viewBox="0 0 24 24" class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/></svg>
                        <span>{{ $errors->first() }}</span>
                    </div>
                @endif

                <div>
                    <label for="email" class="flex items-center gap-1.5 text-sm font-semibold text-ink">
                        <svg viewBox="0 0 24 24" class="h-4 w-4 text-accent" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6 8.5-6"/></svg>
                        {{ __('Email') }}
                    </label>
                    <input id="email" name="email" type="email" required autofocus autocomplete="username" value="{{ old('email') }}"
                           placeholder="{{ __('you@example.org') }}"
                           class="mt-1.5 w-full rounded-xl border border-rule-strong bg-surface px-4 py-3 text-sm text-ink placeholder:text-ink-muted/70 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/25">
                </div>

                <div>
                    <label for="password" class="flex items-center gap-1.5 text-sm font-semibold text-ink">
                        <svg viewBox="0 0 24 24" class="h-4 w-4 text-accent" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 018 0v3"/></svg>
                        {{ __('Password') }}
                    </label>
                    <div class="relative mt-1.5">
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                               placeholder="{{ __('Your password') }}"
                               class="w-full rounded-xl border border-rule-strong bg-surface py-3 pl-4 pr-12 text-sm text-ink placeholder:text-ink-muted/70 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/25">
                        <button type="button" id="toggle-password"
                                class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-ink-muted hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
                                aria-label="{{ __('Show password') }}" aria-controls="password" aria-pressed="false"
                                data-show="{{ __('Show password') }}" data-hide="{{ __('Hide password') }}">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>
                                <path data-slash d="M4 20L20 4" hidden/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <label class="flex items-center gap-2 text-ink-soft">
                        <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-rule-strong text-accent focus:ring-accent">
                        {{ __('Stay signed in') }}
                    </label>
                    <button type="button" id="forgot" class="font-semibold text-accent hover:underline" aria-controls="forgot-note" aria-expanded="false">
                        {{ __('Forgot your password?') }}
                    </button>
                </div>
                <p id="forgot-note" hidden class="-mt-2 rounded-lg bg-accent-soft px-3 py-2 text-xs leading-relaxed text-ink-soft">
                    {{ __('Ask your administrator: they can set a new password for you from the People page. You then choose your own when you sign in.') }}
                </p>

                <button type="submit"
                        class="group flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3.5 text-sm font-semibold text-white shadow-lg shadow-[#1f3a5f]/30 transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#e8b54a] focus-visible:ring-offset-2"
                        style="background: linear-gradient(100deg, #1f3a5f 0%, #13294a 100%);">
                    {{ __('Sign in') }}
                    <svg viewBox="0 0 24 24" class="h-4 w-4 transition group-hover:translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>
            </form>

            <p class="mt-6 flex items-center justify-center gap-1.5 text-xs text-ink-muted">
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 018 0v3"/></svg>
                {{ __('Only for people with an account. Every sign-in is recorded.') }}
            </p>
            <p class="mt-2 text-center text-xs text-ink-muted">
                © {{ now()->year }} {{ platform_name() }} · {{ __('Powered by') }} <span class="font-semibold tracking-[0.14em] text-ink-soft">I-NNOVA</span>
            </p>
        </div>
    </main>
</div>

<script>
    (() => {
        const toggle = document.getElementById('toggle-password');
        const field = document.getElementById('password');
        toggle?.addEventListener('click', () => {
            const showing = field.type === 'text';
            field.type = showing ? 'password' : 'text';
            toggle.setAttribute('aria-pressed', String(! showing));
            toggle.setAttribute('aria-label', showing ? toggle.dataset.show : toggle.dataset.hide);
            toggle.querySelector('[data-slash]').hidden = showing;
        });

        const forgot = document.getElementById('forgot');
        const note = document.getElementById('forgot-note');
        forgot?.addEventListener('click', () => {
            note.hidden = ! note.hidden;
            forgot.setAttribute('aria-expanded', String(! note.hidden));
        });
    })();
</script>
</body>
</html>
