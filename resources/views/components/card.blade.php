@props(['title' => null, 'subtitle' => null])

<section {{ $attributes->merge(['class' => 'rounded border border-rule bg-surface']) }}>
    @if ($title)
        <header class="border-b border-rule px-4 py-3">
            <h2 class="font-serif text-base leading-tight text-ink">{{ $title }}</h2>
            @if ($subtitle)
                <p class="mt-0.5 text-xs text-ink-muted">{{ $subtitle }}</p>
            @endif
        </header>
    @endif

    <div class="p-4">{{ $slot }}</div>
</section>
