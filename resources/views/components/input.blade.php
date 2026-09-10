@props(['name', 'type' => 'text', 'value' => null])

<input type="{{ $type }}"
       name="{{ $name }}"
       id="{{ $name }}"
       value="{{ old($name, $value) }}"
       {{ $attributes->merge([
           'class' => 'w-full rounded border bg-surface px-3 py-2 text-sm text-ink '
               . 'focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent '
               . ($errors->has($name) ? 'border-rose-400' : 'border-rule-strong'),
       ]) }}>
