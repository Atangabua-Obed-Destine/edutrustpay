@extends('layouts.console')

@section('title', $finding->title)
@section('subtitle', $finding->institution->name . ($finding->period ? ' · ' . period_text($finding->period) : ''))

@section('content')

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            @include('body.partials.finding-row', ['finding' => $finding, 'hideAnswer' => true])

            {{-- The figures behind it, frozen at the moment it fired. A restated
                 period rewrites the numbers underneath; the finding must still
                 be able to show what it was actually looking at. --}}
            @if ($finding->figures)
                <div class="rounded border border-rule bg-surface p-4">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">
                        {{ __('The figures behind it') }}
                    </h2>
                    <dl class="grid gap-2 sm:grid-cols-2">
                        @foreach ($finding->figureLines() as $label => $value)
                            <div class="min-w-0 rounded border border-rule px-3 py-2">
                                <dt class="text-xs text-ink-muted">{{ $label }}</dt>
                                <dd class="min-w-0 break-words font-medium tabular-nums">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-3 text-xs text-ink-muted">{{ __('As they stood when this was noticed. They are kept as they were even if the school sends the month again.') }}</p>
                </div>
            @endif

            @if ($finding->periodReport)
                <a href="{{ route('body.institutions.show', ['institution' => $finding->institution, 'period' => $finding->period]) }}"
                   class="inline-block text-sm text-ink-soft underline">
                    {{ __('Open the report this came from') }}
                </a>
            @endif
        </div>

        <div>
            @if ($finding->isOpen())
                @can('finding.resolve')
                    <form method="POST" action="{{ route('body.findings.resolve', $finding) }}"
                          class="space-y-3 rounded border border-rule bg-surface p-4">
                        @csrf

                        <h2 class="text-sm font-semibold text-ink">{{ __('Answer this') }}</h2>
                        <p class="text-xs text-ink-muted">{{ __('Once you know what happened, record it here. It stays on record with your name.') }}</p>

                        {{-- No "dismiss". A finding is answered, never made to
                             disappear — and the name of whoever answered it stays. --}}
                        <div>
                            <label for="status" class="block text-sm text-ink-soft">{{ __('Outcome') }}</label>
                            <select name="status" id="status" required
                                    class="mt-1 w-full rounded border-rule-strong bg-surface py-1.5 text-sm">
                                <option value="{{ \App\Models\Finding::EXPLAINED }}">{{ __('Explained — there is a reason') }}</option>
                                <option value="{{ \App\Models\Finding::ACCEPTED }}">{{ __('Accepted — we will live with it') }}</option>
                            </select>
                        </div>

                        <div>
                            <label for="note" class="block text-sm text-ink-soft">{{ __('Explanation') }}</label>
                            <textarea name="note" id="note" rows="5" required minlength="10"
                                      placeholder="{{ __('What did you find out, and who did you speak to?') }}"
                                      class="mt-1 w-full rounded border-rule-strong bg-surface px-3 py-2 text-sm">{{ old('note') }}</textarea>
                            @error('note')
                                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit"
                                class="w-full rounded bg-accent px-3 py-2 text-sm font-semibold text-white hover:opacity-90">
                            {{ __('Record') }}
                        </button>
                    </form>
                @else
                    <p class="rounded border border-rule bg-surface p-4 text-sm text-ink-muted">
                        {{ __('You can read this but not answer it. Answering belongs to the people accountable for the money.') }}
                    </p>
                @endcan
            @else
                <div class="rounded border border-rule bg-surface p-4">
                    <h2 class="text-sm font-semibold text-ink">{{ __(ucfirst($finding->status)) }}</h2>
                    <p class="mt-2 text-sm text-ink-soft">{{ $finding->resolution_note }}</p>
                    <p class="mt-2 text-xs text-ink-muted">
                        {{ $finding->resolver?->name ?? __('Unknown') }} · {{ $finding->resolved_at?->toDayDateTimeString() }}
                    </p>
                </div>
            @endif
        </div>
    </div>

@endsection
