@props([
    'links' => [],
    'institution',
    'period' => null,
])

{{--
    Where the console stops and the institution's own books begin.

    This platform receives a signed monthly summary — group totals, control
    totals, ageing buckets. It holds no transactions and no names, and it never
    will. So the drill-down chain ends here and hands over rather than
    pretending to go deeper.

    The BROWSER follows these links, not the server: the console makes no
    request, holds no credential for the far end, and needs no way in. That is
    the same promise the settings screen makes to each bursar — outbound only,
    nothing reaches in — and it is why the person clicking has to sign in at the
    other end under their own name.
--}}

@if (! empty($links))
    <section class="no-print">
        <div class="rounded border border-rule bg-surface">
            <header class="border-b border-rule px-4 py-3">
                <h2 class="font-serif text-base leading-tight text-ink">
                    {{ __('The detail behind these figures') }}
                </h2>
                <p class="mt-0.5 text-xs text-ink-muted">
                    {{ __('Opens :institution\'s own system in a new tab. You will sign in there, under your own name — :platform holds none of this detail and is never the authority on an institution\'s books.', [
                        'institution' => $institution->name,
                        'platform' => platform_name(),
                    ]) }}
                </p>
            </header>

            <div class="grid gap-px bg-rule sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($links as $link)
                    <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer"
                       class="group bg-surface px-4 py-3 transition hover:bg-surface-sunk">
                        <span class="flex items-center gap-1.5 text-sm font-medium text-accent group-hover:underline">
                            {{ $link->label }}
                            {{-- The arrow says "this leaves the console" before
                                 the click, not after. --}}
                            <svg viewBox="0 0 12 12" width="11" height="11" fill="none" stroke="currentColor"
                                 stroke-width="1.5" aria-hidden="true" class="shrink-0">
                                <path d="M4.5 2.5h5v5M9.5 2.5 3 9" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <span class="mt-0.5 block text-xs leading-snug text-ink-muted">{{ $link->blurb }}</span>
                    </a>
                @endforeach
            </div>

            @if ($period)
                <p class="border-t border-rule px-4 py-2 text-[11px] text-ink-muted">
                    {{ __('Each link opens on :period. If the figures there disagree with the ones above, the institution\'s own books are right and this month should be restated.', ['period' => $period]) }}
                </p>
            @endif
        </div>
    </section>
@elseif ($institution->endpoint === null)
    {{-- Say why there is nothing here, rather than showing nothing. An operator
         reading this should know it is one field away, not unbuilt. --}}
    <section class="no-print rounded border border-dashed border-rule-strong bg-surface px-4 py-3">
        <p class="text-sm text-ink-soft">{{ __('No link to :institution\'s own system', ['institution' => $institution->name]) }}</p>
        <p class="mt-0.5 text-xs text-ink-muted">
            {{ __('This console holds no transaction detail by design. To reach it, the operator records this institution\'s system address when enrolling it — nothing is fetched from here, the link simply opens in your browser.') }}
        </p>
    </section>
@endif
