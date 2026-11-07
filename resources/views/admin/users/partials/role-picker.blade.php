<div class="mt-4">
    <p class="mb-1 block text-sm font-medium text-ink-soft">{{ __('Roles') }} <span class="text-rose-600" aria-hidden="true">*</span></p>

    @error('role_ids')
        <p class="mb-1 text-xs font-medium text-rose-700">{{ $message }}</p>
    @enderror

    <div class="divide-y divide-rule rounded border border-rule">
        @foreach ($roles as $role)
            <label class="flex items-start gap-2 px-3 py-2 text-sm">
                <input type="checkbox" name="role_ids[]" value="{{ $role->id }}" class="mt-0.5 rounded border-rule-strong"
                       @checked(in_array($role->id, array_map('intval', old('role_ids', $selectedRoles)), true))>
                <span>
                    <span class="font-medium text-ink">{{ $role->display_name }}</span>
                    <span class="block text-xs text-ink-muted">{{ $role->description }}</span>
                </span>
            </label>
        @endforeach
    </div>

    <p class="mt-1 text-xs text-ink-muted">{{ __('A person can hold more than one. You can only give roles within what you hold yourself.') }}</p>
</div>
