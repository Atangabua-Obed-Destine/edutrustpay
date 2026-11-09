@extends('layouts.console')

@section('title', __('Change roles for :name', ['name' => $user->name]))
@section('subtitle', $user->email)

@section('content')

    <x-breadcrumb :items="[__('People') => manage_route('users.index'), $user->name => null]" />

    <form method="POST" action="{{ manage_route('users.update', $user) }}" class="max-w-2xl space-y-6">
        @csrf @method('PUT')

        <x-card :title="__('Roles')">
            @include('admin.users.partials.role-picker')
        </x-card>

        @php $oneBody = $institutions->pluck('body_id')->unique()->count() <= 1; @endphp
        <x-card :title="__('Only for an Institution Liaison')"
                :subtitle="__('A liaison sees one institution and nothing else. Choose it here; leave this empty for everyone else.')">
            <x-field name="institution_id" :label="__('Institution')">
                <x-select name="institution_id" :selected="$user->institution_id" :placeholder="__('— not a liaison —')"
                          :options="$institutions->mapWithKeys(fn ($i) => [$i->id => $oneBody ? $i->name : $i->name . ' (' . $i->body->name . ')'])->all()" />
            </x-field>
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">{{ __('Save changes') }}</button>
            <a href="{{ manage_route('users.index') }}" class="text-sm text-ink-muted hover:underline">{{ __('Cancel') }}</a>
        </div>
    </form>

@endsection
