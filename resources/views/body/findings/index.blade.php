@extends('layouts.console')

@section('title', __('Findings'))
@section('subtitle', __('Controls that fired, most severe first'))

@section('header-actions')
    <nav class="flex gap-1 text-sm">
        @foreach ([\App\Models\Finding::OPEN => __('Open'), \App\Models\Finding::EXPLAINED => __('Explained'), \App\Models\Finding::ACCEPTED => __('Accepted'), 'all' => __('All')] as $key => $label)
            <a href="{{ route('body.findings.index', ['status' => $key]) }}"
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

    @if ($findings->isEmpty())
        <p class="rounded border border-rule bg-surface px-4 py-3 text-sm text-ink-muted">
            {{ __('Nothing here.') }}
        </p>
    @else
        <div class="space-y-2">
            @foreach ($findings as $finding)
                @include('body.partials.finding-row', ['finding' => $finding])
            @endforeach
        </div>

        <div class="mt-5">{{ $findings->links() }}</div>
    @endif

@endsection
