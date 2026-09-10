@props(['name', 'label', 'hint' => null, 'required' => false])

{{-- One field, one place errors are shown. Keeps every form in the operator
     area consistent without anyone remembering to add the error block. --}}
<div {{ $attributes->merge(['class' => 'space-y-1']) }}>
    <label for="{{ $name }}" class="block text-sm font-medium text-ink-soft">
        {{ $label }}
        @if ($required)<span class="text-rose-600" aria-hidden="true">*</span>@endif
    </label>

    {{ $slot }}

    @error($name)
        <p class="text-xs font-medium text-rose-700">{{ $message }}</p>
    @enderror

    @if ($hint)
        <p class="text-xs text-ink-muted">{{ $hint }}</p>
    @endif
</div>
