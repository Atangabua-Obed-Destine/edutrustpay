@props(['name', 'options' => [], 'selected' => null, 'placeholder' => null])

<select name="{{ $name }}" id="{{ $name }}"
        {{ $attributes->merge([
            'class' => 'w-full rounded border bg-surface px-3 py-2 text-sm text-ink '
                . 'focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent '
                . ($errors->has($name) ? 'border-rose-400' : 'border-rule-strong'),
        ]) }}>
    @if ($placeholder)
        <option value="">{{ $placeholder }}</option>
    @endif

    {{-- An array as the label is a group: its key is the heading, its items the options. --}}
    @foreach ($options as $value => $label)
        @if (is_array($label))
            <optgroup label="{{ $value }}">
                @foreach ($label as $groupValue => $groupLabel)
                    <option value="{{ $groupValue }}" @selected((string) old($name, $selected) === (string) $groupValue)>{{ $groupLabel }}</option>
                @endforeach
            </optgroup>
        @else
            <option value="{{ $value }}" @selected((string) old($name, $selected) === (string) $value)>{{ $label }}</option>
        @endif
    @endforeach
</select>
