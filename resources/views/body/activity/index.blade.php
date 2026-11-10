@extends('layouts.console')

@section('title', __('Activity'))
@section('subtitle', __('Who did what in this console, newest first'))

@section('header-actions')
    <x-print-button />
@endsection

@php
    $things = [
        'Institution' => __('a school'), 'Finding' => __('an item to look into'), 'PeriodReport' => __('a monthly report'),
        'InstitutionCapability' => __('what a school sends'), 'RegulationMapping' => __('a fee matching'), 'InstitutionCredential' => __('a connection key'),
        'User' => __('a person'), 'Body' => __('an organisation'), 'Role' => __('a role'), 'Regulation' => __('a rule'),
        'ComplianceCase' => __('a follow-up'), 'RegulationWaiver' => __('a waiver'), 'RegulationAttestation' => __('a rule confirmation'),
        'OnboardingStep' => __('a getting-started step'), 'InstitutionNote' => __('a note on a school'), 'BudgetSheet' => __('a budget sheet'),
    ];

    /** The entry as one plain sentence, after the person's name. */
    $describe = function ($entry) use ($things, $schoolNames) {
        $type = class_basename((string) $entry->model_type);
        $school = $type === 'Institution' ? ($schoolNames[$entry->model_id] ?? null) : ($schoolNames[$entry->new_values['institution_id'] ?? 0] ?? null);
        $month = isset($entry->new_values['period']) && is_string($entry->new_values['period']) ? period_text($entry->new_values['period']) : null;
        $of = $school ? ' '.__('of :school', ['school' => $school]) : '';
        $for = $month ? ' '.__('for :month', ['month' => $month]) : '';

        return match ($entry->action) {
            'auth.login' => __('signed in'),
            'daybook.viewed' => __('opened the daybook').$of.$for,
            'staff.viewed' => __('opened the staff list').$of.$for,
            'daybook.exported' => __('downloaded the daybook').$of.$for,
            'staff.exported' => __('downloaded the staff list').$of.$for,
            'budget.exported' => __('downloaded the budget sheet').$of.$for,
            'consolidation.exported' => __('downloaded All schools together').$for,
            'viewed' => $type === 'Institution' ? __('opened the page of :school', ['school' => $school ?? __('a school')]) : __('opened :thing', ['thing' => $things[$type] ?? __('a record')]),
            'credential.issued' => __('issued a connection key').$of,
            'credential.rotated' => __('replaced a connection key').$of,
            'credential.revoked' => __('revoked a connection key').$of,
            'user.invited' => __('invited someone'),
            'user.invite_accepted' => __('accepted their invitation'),
            'role.permissions_changed' => __('changed what a role may do'),
            'platform.installed' => __('installed the console'),
            'created' => __('added :thing', ['thing' => $things[$type] ?? __('a record')]).$of,
            'updated' => ($type === 'Finding' ? __('answered an item to look into') : __('changed :thing', ['thing' => $things[$type] ?? __('a record')])).$of,
            'deleted' => __('removed :thing', ['thing' => $things[$type] ?? __('a record')]).$of,
            default => str_replace(['.', '_'], ' ', $entry->action).$of,
        };
    };
@endphp

@section('content')

    <p class="mb-4 max-w-3xl text-sm text-ink-soft">{{ __('Every sign-in, every opening of a daybook or staff list, every download, answer and change is recorded here with the person\'s name. Nothing can be removed from it.') }}</p>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded border border-rule bg-surface px-4 py-3 text-sm">
        <div>
            <label for="who" class="block text-xs font-medium text-ink-muted">{{ __('Person') }}</label>
            <select id="who" name="who" class="mt-1 rounded border border-rule-strong bg-surface py-1.5 text-sm">
                <option value="">{{ __('Everyone') }}</option>
                @foreach ($people as $person)
                    <option value="{{ $person->id }}" @selected($filters['who'] === (string) $person->id)>{{ $person->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="kind" class="block text-xs font-medium text-ink-muted">{{ __('What') }}</label>
            <select id="kind" name="kind" class="mt-1 rounded border border-rule-strong bg-surface py-1.5 text-sm">
                @foreach (['' => __('Everything'), 'signins' => __('Sign-ins'), 'opened' => __('Pages opened'), 'downloads' => __('Downloads'), 'changes' => __('Changes and answers'), 'keys' => __('Connection keys'), 'people' => __('People and roles')] as $key => $label)
                    <option value="{{ $key }}" @selected($filters['kind'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @if ($institutions->count() > 1)
            <div>
                <label for="school" class="block text-xs font-medium text-ink-muted">{{ __('School') }}</label>
                <select id="school" name="school" class="mt-1 rounded border border-rule-strong bg-surface py-1.5 text-sm">
                    <option value="">{{ __('All schools') }}</option>
                    @foreach ($institutions as $option)
                        <option value="{{ $option->uuid }}" @selected($filters['school'] === $option->uuid)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <label class="flex items-center gap-2 pb-1.5 text-ink-soft">
            <input type="checkbox" name="system" value="1" @checked($filters['system']) class="rounded border-rule-strong">
            {{ __('Include what the console did by itself') }}
        </label>
        <button type="submit" class="rounded bg-accent px-3 py-1.5 font-semibold text-white">{{ __('Show') }}</button>
    </form>

    <div class="overflow-x-auto rounded border border-rule">
        <table class="min-w-full divide-y divide-rule text-sm">
            <thead class="bg-surface-sunk text-left text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    <th class="px-4 py-2 font-semibold">{{ __('When') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('Who') }}</th>
                    <th class="px-4 py-2 font-semibold">{{ __('What') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule bg-surface">
                @forelse ($entries as $entry)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2 text-xs text-ink-muted">{{ $entry->created_at?->translatedFormat('j M Y, H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $entry->user?->name ?? __('the console') }}</td>
                        <td class="px-4 py-2 text-ink-soft">{{ $describe($entry) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-sm italic text-ink-muted">{{ __('Nothing matches.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $entries->links() }}</div>

@endsection
