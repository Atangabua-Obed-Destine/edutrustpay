@props(['items' => []])

{{-- The operator area nests three deep (body > institution > credential), and
     without this it is easy to lose track of which body you are inside. --}}
<nav class="mb-4 flex flex-wrap items-center gap-1.5 text-sm text-ink-muted">
    @foreach ($items as $label => $url)
        @if ($url && ! $loop->last)
            <a href="{{ $url }}" class="hover:text-ink hover:underline">{{ $label }}</a>
        @else
            <span @class(['font-medium text-ink' => $loop->last])>{{ $label }}</span>
        @endif

        @unless ($loop->last)
            <span aria-hidden="true">/</span>
        @endunless
    @endforeach
</nav>
