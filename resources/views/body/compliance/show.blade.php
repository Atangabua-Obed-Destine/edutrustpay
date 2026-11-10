@extends('layouts.console')

@section('title', $institution->name)
@section('subtitle', $regulation->title)

@section('header-actions')
    <x-print-button />
@endsection

@section('content')

    <x-breadcrumb :items="[__('Compliance') => route('body.compliance.index'), $institution->name => null]" />

    <div class="mb-6 rounded border border-rule bg-surface px-4 py-3">
        @include('body.compliance.partials.state', ['state' => $result->state])
        <p class="mt-2 text-sm text-ink">{{ $result->summary }}</p>
        @if ($result->state === 'waived' && $result->found !== 'waived')
            <p class="mt-1 text-xs text-ink-muted">{{ __('Without the waiver this would read:') }} @include('body.compliance.partials.state', ['state' => $result->found])</p>
        @endif
        @if ($regulation->description)
            <p class="mt-2 text-xs text-ink-muted">{{ $regulation->description }}</p>
        @endif
    </div>

    {{-- Full width: the comparison is the point of this page, and it needs the room. --}}
    @if ($regulation->check_type === \App\Models\Regulation::FEE_SCHEDULE)
        <div class="mb-6">
                <x-card :title="__('What the school charges')"
                        :subtitle="$result->report ? __('From the report for :period. No student is named. Problems are listed first. Where the school names a class or fee differently from your rule, choose under “Counts as” which line of the rule it is, then click Save matching; it stays matched.', ['period' => period_text($result->report->period)]) : null">
                    @if ($result->lines->isEmpty())
                        <p class="text-sm text-ink-muted">{{ __('No fee figures to compare yet. They arrive with the school\'s monthly report.') }}</p>
                    @else
                        @php
                            $lineStates = [
                                'ok' => ['text-emerald-700', __('within schedule')],
                                'over' => ['font-medium text-rose-700', __('above schedule')],
                                'under' => ['font-medium text-rose-700', __('below schedule')],
                                'unmatched' => ['text-amber-700', __('not matched')],
                                'ignored' => ['text-ink-muted', __('outside this rule')],
                            ];
                        @endphp
                        <form method="POST" action="{{ route('body.compliance.mappings', [$regulation->id, $institution]) }}">
                            @csrf
                            <div class="-mx-4 overflow-x-auto">
                                <table class="min-w-full divide-y divide-rule text-sm">
                                    <thead class="text-left text-xs uppercase tracking-wide text-ink-muted">
                                        <tr>
                                            <th class="px-4 py-2 font-semibold">{{ __('Class') }}</th>
                                            <th class="px-4 py-2 font-semibold">{{ __('Fee') }}</th>
                                            <th class="px-4 py-2 text-right font-semibold">{{ __('Charged') }}</th>
                                            <th class="px-4 py-2 text-right font-semibold">{{ __('Allowed') }}</th>
                                            <th class="px-4 py-2 text-right font-semibold">{{ __('Students') }}</th>
                                            <th class="px-4 py-2 font-semibold">{{ __('Result') }}</th>
                                            @can('compliance.manage')
                                                <th class="px-4 py-2 font-semibold" title="{{ __('When the school names a class or fee differently from your rule, choose which line of the rule it is, then Save matching. It stays matched.') }}">{{ __('Counts as') }}</th>
                                            @endcan
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-rule">
                                        @foreach ($result->lines->sortBy(fn ($l) => ['over' => 0, 'under' => 0, 'unmatched' => 1, 'ok' => $l->varies ? 2 : 3, 'ignored' => 4][$l->state]) as $i => $line)
                                            <tr @class([
                                                'bg-rose-50' => in_array($line->state, ['over', 'under'], true),
                                                'bg-amber-50' => $line->state === 'unmatched',
                                            ])>
                                                <td class="px-4 py-2 text-ink-soft">{{ $line->level }}</td>
                                                <td class="px-4 py-2 text-ink-soft">{{ $line->category }}</td>
                                                <td class="px-4 py-2 text-right tabular-nums">{{ money_text($line->amount) }}</td>
                                                <td class="px-4 py-2 text-right tabular-nums text-ink-muted">{{ $line->allowed !== null ? money_text($line->allowed) : '—' }}</td>
                                                <td class="px-4 py-2 text-right tabular-nums text-ink-muted">{{ $line->students }}</td>
                                                <td class="whitespace-nowrap px-4 py-2 text-xs {{ $lineStates[$line->state][0] }}">
                                                    {{ $lineStates[$line->state][1] }}
                                                    @if ($line->difference !== null && (float) $line->difference !== 0.0 && $line->state !== 'ok')
                                                        ({{ (float) $line->difference > 0 ? '+' : '' }}{{ money_text($line->difference) }})
                                                    @endif
                                                    @if ($line->varies)
                                                        <span class="block font-normal text-amber-700">{{ __('more than one amount in this class') }}</span>
                                                    @endif
                                                </td>
                                                @can('compliance.manage')
                                                    <td class="px-4 py-2">
                                                        <input type="hidden" name="mappings[{{ $i }}][level]" value="{{ $line->level }}">
                                                        <input type="hidden" name="mappings[{{ $i }}][category]" value="{{ $line->category }}">
                                                        <select name="mappings[{{ $i }}][target]" class="w-44 rounded border border-rule-strong bg-surface px-2 py-1 text-xs">
                                                            <option value="">{{ __('match by name') }}</option>
                                                            @foreach ($regulation->feeItems as $item)
                                                                <option value="{{ $item->id }}" @selected($line->item_id === $item->id)>{{ $item->label() }}</option>
                                                            @endforeach
                                                            <option value="ignore" @selected($line->state === 'ignored')>{{ __('outside this rule') }}</option>
                                                        </select>
                                                    </td>
                                                @endcan
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @can('compliance.manage')
                                <button type="submit" class="mt-3 rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">{{ __('Save matching') }}</button>
                            @endcan
                        </form>
                    @endif
                </x-card>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">

            @if ($regulation->check_type !== \App\Models\Regulation::FEE_SCHEDULE)
                <x-card :title="__('Confirmed by a person')" :subtitle="__('The system cannot measure this rule, so someone has to confirm it.')">
                    @if ($result->attestation)
                        <p class="text-sm text-ink">{{ $result->attestation->note }}</p>
                        <p class="mt-1 text-xs text-ink-muted">
                            {{ __(':name, :date. Valid until :until.', [
                                'name' => $result->attestation->attester?->name ?? __('someone'),
                                'date' => $result->attestation->created_at->toFormattedDateString(),
                                'until' => $result->attestation->valid_until->toFormattedDateString(),
                            ]) }}
                        </p>
                    @else
                        <p class="text-sm text-ink-muted">{{ __('Nobody has confirmed this yet.') }}</p>
                    @endif

                    @can('compliance.follow_up')
                        <form method="POST" action="{{ route('body.compliance.attest', [$regulation->id, $institution]) }}" class="mt-4 grid gap-3 border-t border-rule pt-4 sm:grid-cols-2">
                            @csrf
                            <x-field name="outcome" :label="__('What did you find?')" required>
                                <x-select name="outcome" :options="['complies' => __('It is being followed'), 'breach' => __('It is not being followed')]" />
                            </x-field>
                            <x-field name="valid_until" :label="__('Valid until')" required>
                                <x-input name="valid_until" type="date" required />
                            </x-field>
                            <x-field name="note" :label="__('How do you know?')" required class="sm:col-span-2">
                                <x-input name="note" required :placeholder="__('e.g. Visited on 12 November and saw the minutes')" />
                            </x-field>
                            <div><button type="submit" class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">{{ __('Record') }}</button></div>
                        </form>
                    @endcan
                </x-card>
            @endif

            <x-card :title="__('Follow-up')">
                @forelse ($cases as $case)
                    <div class="mb-3 rounded border border-rule px-3 py-2 text-sm">
                        <p class="text-ink">{{ $case->note }}</p>
                        <p class="mt-1 text-xs {{ $case->isOverdue() ? 'font-medium text-rose-700' : 'text-ink-muted' }}">
                            {{ __('With :name', ['name' => $case->assignee?->name ?? __('nobody yet')]) }}
                            @if ($case->due_on) · {{ __('due :date', ['date' => $case->due_on->toFormattedDateString()]) }} @endif
                            @if ($case->isOverdue()) · {{ __('overdue') }} @endif
                        </p>
                        @if ($case->status === \App\Models\ComplianceCase::CLOSED)
                            <p class="mt-1 text-xs text-emerald-700">{{ __('Closed :date: :outcome', ['date' => $case->closed_at->toFormattedDateString(), 'outcome' => $case->outcome]) }}</p>
                        @else
                            @can('compliance.follow_up')
                                <form method="POST" action="{{ route('body.compliance.cases.close', [$regulation->id, $institution, $case]) }}" class="mt-2 flex flex-wrap gap-2">
                                    @csrf
                                    <input type="text" name="outcome" required minlength="5" placeholder="{{ __('How did it end?') }}" class="min-w-56 flex-1 rounded border border-rule-strong px-2 py-1 text-xs">
                                    <button type="submit" class="rounded border border-rule-strong px-2.5 py-1 text-xs font-medium hover:bg-surface-sunk">{{ __('Close') }}</button>
                                </form>
                            @endcan
                        @endif
                    </div>
                @empty
                    <p class="mb-3 text-sm text-ink-muted">{{ __('No follow-up yet.') }}</p>
                @endforelse

                @can('compliance.follow_up')
                    <form method="POST" action="{{ route('body.compliance.cases.open', [$regulation->id, $institution]) }}" class="grid gap-3 border-t border-rule pt-4 sm:grid-cols-2">
                        @csrf
                        <x-field name="note" :label="__('What needs doing?')" required class="sm:col-span-2">
                            <x-input name="note" required />
                        </x-field>
                        <x-field name="assigned_to" :label="__('Give it to')">
                            <x-select name="assigned_to" :placeholder="__('— nobody yet —')" :options="$people->pluck('name', 'id')->all()" />
                        </x-field>
                        <x-field name="due_on" :label="__('Due')">
                            <x-input name="due_on" type="date" />
                        </x-field>
                        <div><button type="submit" class="rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">{{ __('Open follow-up') }}</button></div>
                    </form>
                @endcan
            </x-card>
        </div>

        <div class="min-w-0 space-y-6">
            <x-card :title="__('Waiver')" :subtitle="__('An approved exception, with a reason and an end date.')">
                @if ($result->waiver)
                    <p class="text-sm text-ink">{{ $result->waiver->reason }}</p>
                    <p class="mt-1 text-xs text-ink-muted">
                        {{ __('Granted by :name, until :date.', ['name' => $result->waiver->grantor?->name ?? __('someone'), 'date' => $result->waiver->expires_on->toFormattedDateString()]) }}
                    </p>
                    @can('compliance.waive')
                        <form method="POST" action="{{ route('body.compliance.waiver.revoke', [$regulation->id, $institution, $result->waiver]) }}" class="mt-3" data-confirm="{{ __('End this waiver now?') }}">
                            @csrf
                            <button type="submit" class="text-xs font-medium text-rose-600 hover:underline">{{ __('End this waiver') }}</button>
                        </form>
                    @endcan
                @else
                    @can('compliance.waive')
                        <form method="POST" action="{{ route('body.compliance.waiver', [$regulation->id, $institution]) }}" class="space-y-3">
                            @csrf
                            <x-field name="reason" :label="__('Why?')" required>
                                <x-input name="reason" required />
                            </x-field>
                            <x-field name="expires_on" :label="__('Until')" required>
                                <x-input name="expires_on" type="date" required />
                            </x-field>
                            <button type="submit" class="rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">{{ __('Grant waiver') }}</button>
                        </form>
                    @else
                        <p class="text-sm text-ink-muted">{{ __('None.') }}</p>
                    @endcan
                @endif
            </x-card>

            @can('institution.view')
                <a href="{{ route('body.institutions.show', $institution) }}" class="block text-sm text-ink-soft underline-offset-2 hover:underline">{{ __('See this school\'s figures') }}</a>
            @endcan
        </div>
    </div>

@endsection
