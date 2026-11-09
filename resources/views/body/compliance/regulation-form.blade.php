@extends('layouts.console')

@php $isFees = old('check_type', $regulation->check_type) === \App\Models\Regulation::FEE_SCHEDULE; @endphp

@section('title', $regulation->exists ? __('Edit :title', ['title' => $regulation->title]) : ($isFees ? __('New fee schedule') : __('New rule, confirmed by a person')))
@section('subtitle', $isFees ? __('Each school\'s reported fees are compared with this automatically.') : __('For rules the system cannot measure. Someone records whether each school follows it.'))

@section('content')

    <x-breadcrumb :items="[__('Compliance') => route('body.compliance.index'), __('Rules') => route('body.compliance.regulations.index'), ($regulation->exists ? $regulation->title : __('New')) => null]" />

    <form method="POST" action="{{ $regulation->exists ? route('body.compliance.regulations.update', $regulation) : route('body.compliance.regulations.store') }}" class="max-w-4xl space-y-6">
        @csrf
        @if ($regulation->exists) @method('PUT') @endif
        <input type="hidden" name="check_type" value="{{ old('check_type', $regulation->check_type) }}">

        <x-card :title="__('The rule')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="title" :label="__('Title')" required class="sm:col-span-2">
                    <x-input name="title" :value="$regulation->title" required autofocus :placeholder="$isFees ? __('e.g. Secondary school fees 2026/2027') : __('e.g. PTA meeting held each term')" />
                </x-field>
                <x-field name="description" :label="__('Details')" class="sm:col-span-2">
                    <x-input name="description" :value="$regulation->description" />
                </x-field>
                <x-field name="area" :label="__('Area')" required>
                    <x-select name="area" :selected="$regulation->area" :options="collect(\App\Models\Regulation::AREAS)->map(fn ($a) => __($a))->all()" />
                </x-field>
                <x-field name="applies_to" :label="__('Applies to')">
                    <x-select name="applies_to" :selected="$regulation->applies_to" :placeholder="__('All institutions')" :options="\App\Support\InstitutionType::plurals()" />
                </x-field>
                <x-field name="effective_from" :label="__('In force from')" required
                         :hint="$isFees ? __('Months before this are not judged, so a school whose latest report is older reads as cannot be checked until its next report.') : null">
                    <x-input name="effective_from" type="date" :value="$regulation->effective_from?->format('Y-m-d')" required />
                </x-field>
                <x-field name="effective_to" :label="__('Until')" :hint="__('Leave empty if it has no end date.')">
                    <x-input name="effective_to" type="date" :value="$regulation->effective_to?->format('Y-m-d')" />
                </x-field>
            </div>
        </x-card>

        @if ($isFees)
            <x-card :title="__('Fees')" :subtitle="__('One line per class and fee. A school whose names differ can be matched to these lines afterwards.')">
                @if (! $regulation->exists && $institutions->isNotEmpty())
                    <div class="mb-4 flex flex-wrap items-center gap-2 rounded border border-rule bg-surface-sunk px-3 py-2 text-sm">
                        <label for="copy_from" class="text-ink-soft">{{ __('Start from what a school charges:') }}</label>
                        <select id="copy_from" class="rounded border border-rule-strong bg-surface px-2 py-1 text-sm"
                                onchange="if (this.value) window.location = '{{ route('body.compliance.regulations.create') }}?copy_from=' + this.value">
                            <option value="">{{ __('— choose —') }}</option>
                            @foreach ($institutions as $school)
                                <option value="{{ $school->uuid }}" @selected($copyFrom?->id === $school->id)>{{ $school->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($copyFrom)
                        <p class="mb-3 text-xs text-amber-700">{{ __('These are :name\'s amounts, filled in to save typing. Change them to what you actually allow.', ['name' => $copyFrom->name]) }}</p>
                    @endif
                @endif

                @error('items')
                    <p class="mb-3 text-xs font-medium text-rose-700">{{ $message }}</p>
                @enderror

                <x-field name="fee_mode" :label="__('What counts as a breach?')" class="mb-4 max-w-md">
                    <x-select name="fee_mode" :selected="$regulation->fee_mode" :options="['maximum' => __('Charging more than this'), 'exact' => __('Charging more or less than this')]" />
                </x-field>

                @php
                    $rows = old('items', $items);
                    if ($rows === []) { $rows = [['level' => '', 'category' => '', 'amount' => '']]; }
                @endphp

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm" id="fee-rows">
                        <thead class="text-left text-xs uppercase tracking-wide text-ink-muted">
                            <tr>
                                <th class="py-1 pr-2 font-semibold">{{ __('Class') }}</th>
                                <th class="py-1 pr-2 font-semibold">{{ __('Fee') }}</th>
                                <th class="py-1 pr-2 font-semibold">{{ __('Amount') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $i => $row)
                                <tr>
                                    <td class="py-1 pr-2"><input type="text" name="items[{{ $i }}][level]" value="{{ $row['level'] ?? '' }}" placeholder="{{ __('e.g. Form 1') }}" class="w-full min-w-40 rounded border border-rule-strong bg-surface px-2 py-1.5"></td>
                                    <td class="py-1 pr-2"><input type="text" name="items[{{ $i }}][category]" value="{{ $row['category'] ?? '' }}" placeholder="{{ __('e.g. Tuition') }}" class="w-full min-w-40 rounded border border-rule-strong bg-surface px-2 py-1.5"></td>
                                    <td class="py-1 pr-2"><input type="text" inputmode="decimal" name="items[{{ $i }}][amount]" value="{{ $row['amount'] ?? '' }}" class="w-32 rounded border border-rule-strong bg-surface px-2 py-1.5 text-right tabular-nums"></td>
                                    <td class="py-1"><button type="button" class="text-xs text-ink-muted hover:underline" onclick="this.closest('tr').remove()">{{ __('Remove') }}</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <button type="button" id="add-fee" class="mt-3 rounded border border-rule-strong px-3 py-1.5 text-sm font-medium hover:bg-surface-sunk">{{ __('Add a line') }}</button>
            </x-card>
        @endif

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                {{ $regulation->exists ? __('Save changes') : __('Put in force') }}
            </button>
            <a href="{{ route('body.compliance.regulations.index') }}" class="text-sm text-ink-muted hover:underline">{{ __('Cancel') }}</a>
        </div>
    </form>

@endsection

@push('scripts')
    <script>
        document.getElementById('add-fee')?.addEventListener('click', function () {
            var body = document.querySelector('#fee-rows tbody');
            var row = body.rows[body.rows.length - 1];
            var next = row.cloneNode(true);
            var index = Date.now();

            next.querySelectorAll('input').forEach(function (input) {
                input.name = input.name.replace(/items\[\d+\]/, 'items[' + index + ']');
                input.value = '';
            });

            body.appendChild(next);
            next.querySelector('input').focus();
        });
    </script>
@endpush
