@props(['aggregate'])

{{--
    The coverage statement that must accompany any consolidated total which is
    not complete (brief §7.4).

    It is rendered from the same object that produced the total, so it cannot
    drift from it or be left off when someone adds a new summary card.
--}}

<p @class([
    'text-sm',
    'text-ink-muted' => $aggregate->isUnqualified(),
    'font-medium text-amber-700' => ! $aggregate->isUnqualified(),
])>
    {{ $aggregate->coverageStatement() }}

    @if ($aggregate->hasStale())
        {{ __('Some figures are stale.') }}
    @endif
</p>

@if (! $aggregate->isComplete())
    <ul class="mt-1 text-xs text-ink-muted">
        @foreach ($aggregate->missingLabels() as $label => $reason)
            <li>{{ $label }} — <span class="italic">{{ $reason }}</span></li>
        @endforeach
    </ul>
@endif
