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

    @foreach ($options as $value => $label)
        <option value="{{ $value }}" @selected((string) old($name, $selected) === (string) $value)>{{ $label }}</option>
    @endforeach
</select>
