@props([
    'figure',
    'currency' => null,
    'class' => '',
])

{{--
    Renders a Figure.

    There is no branch in this file that prints a number for a missing figure,
    and no default value anywhere. That is the point: the rule "missing is not
    zero" is enforced by the component rather than by everyone who uses it
    remembering. A template can only show a number if the institution actually
    sent one.

    Missing states are rendered in muted italics with a tooltip, so they read as
    an absence of information rather than as a quantity — someone scanning the
    consolidated position should never mistake one for a small number.
--}}

@if ($figure->hasValue())
    <span class="{{ $class }} tabular-nums {{ $figure->isStale() ? 'text-amber-700' : '' }}"
          @if ($figure->asOf()) title="{{ __('As at :time', ['time' => $figure->asOf()->toDayDateTimeString()]) }}" @endif>
        {{ $figure->formatted() }}@if ($currency)<span class="ml-1 text-xs text-ink-muted">{{ $currency }}</span>@endif

        @if ($figure->isStale())
            {{-- Stale is not current. Say so on the figure, not in a footnote. --}}
            <span class="ml-1 text-xs font-medium">{{ __('stale') }}</span>
        @endif
    </span>
@else
    <span class="{{ $class }} font-sans text-sm font-normal italic text-ink-muted"
          @if ($figure->note()) title="{{ $figure->note() }}" @endif>
        {{ $figure->label() }}
    </span>
@endif
