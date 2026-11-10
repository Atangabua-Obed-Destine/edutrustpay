@extends('layouts.console')

@section('title', __('To look into'))
@section('subtitle', __('What the system noticed and cannot explain, most serious first'))

@section('header-actions')
    <nav class="flex gap-1 text-sm">
        @foreach ([\App\Models\Finding::OPEN => __('Open'), \App\Models\Finding::EXPLAINED => __('Explained'), \App\Models\Finding::ACCEPTED => __('Accepted'), 'all' => __('All')] as $key => $label)
            <a href="{{ route('body.findings.index', array_filter(['status' => $key, 'institution' => $school?->uuid])) }}"
               @class([
                   'rounded px-2.5 py-1',
                   'bg-accent text-white' => $status === $key,
                   'text-ink-soft hover:bg-surface-sunk' => $status !== $key,
               ])>
                {{ $label }}@isset($counts[$key]) <span class="opacity-60">{{ $counts[$key] }}</span> @endisset
            </a>
        @endforeach
    </nav>
@endsection

@section('content')

    @if ($institutions->count() > 1)
        <form method="GET" class="mb-4 flex flex-wrap items-center gap-2 text-sm">
            <input type="hidden" name="status" value="{{ $status }}">
            <label for="institution" class="text-ink-muted">{{ __('School') }}</label>
            <select id="institution" name="institution" onchange="this.form.submit()" class="rounded border-rule-strong bg-surface py-1.5 text-sm">
                <option value="">{{ __('All schools') }}</option>
                @foreach ($institutions as $option)
                    <option value="{{ $option->uuid }}" @selected($school?->uuid === $option->uuid)>{{ $option->name }}</option>
                @endforeach
            </select>
        </form>
    @endif

    @if ($findings->isEmpty())
        <p class="rounded border border-rule bg-surface px-4 py-3 text-sm text-ink-muted">
            {{ __('Nothing here.') }}
        </p>
    @else
        <p class="mb-3 text-sm text-ink-muted">{{ __('These are questions to ask, not proof of anything. Open one to read it, and answer it once you know what happened. Each one is a school and a kind of question; it may cover several months.') }}</p>
        <div class="space-y-2">
            @foreach ($groups as $group)
                @include('body.partials.finding-compact', ['group' => $group])
            @endforeach
        </div>

        <div class="mt-5">{{ $findings->links() }}</div>
    @endif

@endsection
