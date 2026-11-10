@extends('layouts.console')

@section('title', $institution->exists ? __('Edit :name', ['name' => $institution->name]) : __('Add an institution'))

@php
    $forOperator = request()->routeIs('admin.*');

    // What a blank setting falls back to, said with the name it falls back to.
    $home = $bodies->firstWhere('id', (int) old('body_id', $institution->body_id)) ?? $bodies->first();
    $homeName = $home?->name ?? __('the organisation');
    $homeDays = $home?->notReportingDays() ?? (int) config('findings.rules.not_reporting.days', 35);
    $homeMonth = \Carbon\Carbon::create(null, $home?->fiscal_year_start_month ?? 1, 1)->translatedFormat('F');
@endphp

@section('content')

    <x-breadcrumb :items="array_filter([
        (request()->routeIs('admin.*') ? __('Institutions') : __('All institutions')) => manage_route('institutions.index'),
        ($institution->exists ? $institution->name : null) => $institution->exists ? manage_route('institutions.show', $institution) : null,
        ($institution->exists ? __('Edit') : __('New')) => null,
    ])" />

    <form method="POST"
          action="{{ $institution->exists ? manage_route('institutions.update', $institution) : manage_route('institutions.store') }}"
          class="max-w-3xl space-y-6">
        @csrf
        @if ($institution->exists) @method('PUT') @endif

        <x-card :title="__('Identity')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="name" :label="__('Name')" required class="sm:col-span-2">
                    <x-input name="name" :value="$institution->name" required autofocus />
                </x-field>

                {{--
                    Where it sits. The operator places institutions across every
                    body, so they always choose. A body's own people only choose
                    when there is a real choice, such as a deanery inside the
                    archdiocese; with one possible answer the question is noise.
                --}}
                @if ($forOperator)
                    <x-field name="body_id" :label="__('Belongs to')" required>
                        <x-select name="body_id" :selected="$institution->body_id" :placeholder="__('— choose a body —')"
                                  :options="$bodies->mapWithKeys(fn ($b) => [$b->id => $b->indented_name])->all()" />
                    </x-field>
                @elseif ($bodies->count() === 1)
                    <input type="hidden" name="body_id" value="{{ $bodies->first()->id }}">
                @else
                    <x-field name="body_id" :label="__('Part of')" required
                             :hint="__('Choose a deanery or other part if the institution answers to one. People there will see it too.')">
                        <x-select name="body_id" :selected="$institution->body_id ?? $bodies->first()->id"
                                  :options="$bodies->mapWithKeys(fn ($b, $i) => [$b->id => $i === 0 ? __(':name (directly)', ['name' => $b->name]) : $b->indented_name])->all()" />
                    </x-field>
                @endif

                <x-field name="type" :label="__('Type')" required
                         :hint="__('Decides which rules apply and which parts of the report can. A hospital, pharmacy or shop has no pupils, so enrolment and school fees show as “n/a” for it, not as missing.')">
                    <x-select name="type" :selected="$institution->type" :options="\App\Support\InstitutionType::grouped()" />
                </x-field>

                <x-field name="system" :label="__('Accounting system')" required
                         :hint="__('The software it keeps its books in. That software sends its reports here.')">
                    <x-select name="system" :selected="$institution->system" :options="[
                        'edutrustshsm' => 'edutrustshsm — ' . __('nursery to high school'),
                        'paxhitestbed' => 'paxhitestbed — ' . __('higher education'),
                        'posinnova' => 'posinnova — ' . __('hospitals, pharmacies, shops'),
                    ]" />
                </x-field>

@if (request()->routeIs('admin.*'))
                                <x-field name="slug" :label="__('Slug')" :hint="__('Optional. Generated from the name if blank.')">
                    <x-input name="slug" :value="$institution->slug" />
                </x-field>
                @endif

                <x-field name="city" :label="__('City')"><x-input name="city" :value="$institution->city" /></x-field>
            </div>
        </x-card>

        <x-card :title="__('Contact')" :subtitle="__('Who to ring when a report stops arriving.')">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="contact_name" :label="__('Name')"><x-input name="contact_name" :value="$institution->contact_name" /></x-field>
                <x-field name="contact_email" :label="__('Email')"><x-input name="contact_email" type="email" :value="$institution->contact_email" /></x-field>
                <x-field name="contact_phone" :label="__('Phone')"><x-input name="contact_phone" :value="$institution->contact_phone" /></x-field>
            </div>
        </x-card>

        <x-card :title="__('Its system and reporting')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="connectivity_mode" :label="__('How it runs')" required
                         :hint="$forOperator ? __('Either way it sends its own reports out, so reporting does not depend on the tunnel. The tunnel address only matters for the links below.') : __('Either way, its system sends its reports here by itself.')">
                    <select name="connectivity_mode" id="connectivity_mode"
                            class="w-full rounded border border-rule-strong bg-surface px-3 py-2 text-sm text-ink
                                   focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option value="push" @selected(old('connectivity_mode', $institution->connectivity_mode ?? 'push') === 'push')>
                            {{ __('Hosted online') }}
                        </option>
                        <option value="tunnel" @selected(old('connectivity_mode', $institution->connectivity_mode) === 'tunnel')>
                            {{ $forOperator ? __('Installed on campus, reached online through cloudflared') : __('Installed on a computer at the institution') }}
                        </option>
                    </select>
                </x-field>

                {{-- A record of what this institution actually sends, not a
                     gate on what it may send.

                     Free text here meant "9.9" could be saved and nothing would
                     ever object. Worse, it read as a rule and is not one: ingest
                     accepts any version this SERVER supports, and then writes
                     back what actually arrived. Gating on it would take an
                     institution offline for a bookkeeping mismatch, which is a
                     poor trade for no safety. --}}
                @if (request()->routeIs('admin.*'))
                <x-field name="contract_version" :label="__('Contract version')" required
                         :hint="__('What this institution reports in. Updated automatically to whatever it actually sends, so during a staged rollout this shows where each one has got to. :n version(s) exist so far.', ['n' => count(\EdutrustPay\Contract\ContractVersion::SUPPORTED)])">
                    <x-select name="contract_version"
                              :selected="$institution->contract_version ?? \EdutrustPay\Contract\ContractVersion::CURRENT"
                              :options="collect(\EdutrustPay\Contract\ContractVersion::SUPPORTED)
                                  ->mapWithKeys(fn ($v) => [$v => $v . ($v === \EdutrustPay\Contract\ContractVersion::CURRENT ? ' — ' . __('current') : '')])
                                  ->all()" />
                </x-field>
                @else
                    <input type="hidden" name="contract_version" value="{{ $institution->contract_version ?? \EdutrustPay\Contract\ContractVersion::CURRENT }}">
                @endif

                <x-field name="endpoint" :label="__('Address of its system')" :hint="__('Optional. Lets you open an entry in its own system from this console. Nothing here ever changes anything there.')" class="sm:col-span-2">
                    <x-input name="endpoint" type="url" :value="$institution->endpoint" placeholder="https://" />
                </x-field>

                <x-field name="not_reporting_days" :label="__('Warn after how many days without a report')"
                         :hint="__('Leave blank to use the setting for :name, :days days.', ['name' => $homeName, 'days' => $homeDays])">
                    <x-input name="not_reporting_days" type="number" min="1" max="365" :value="$institution->not_reporting_days" />
                </x-field>

                {{-- The institution's OWN year, because its year-to-date
                     statement has to reconcile against the sheet its own bursar
                     works from. A school's year follows its terms; the body
                     that owns it may well keep calendar books. --}}
                <x-field name="fiscal_year_start_month" :label="__('Its year starts in')"
                         :hint="__('Change it only if its year starts in another month, such as a school year opening in September. It changes no figure, only which twelve months “year so far” adds up.')">
                    <x-select name="fiscal_year_start_month" :selected="$institution->fiscal_year_start_month"
                              :placeholder="__('— same as :name (:month) —', ['name' => $homeName, 'month' => $homeMonth])"
                              :options="collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Carbon\Carbon::create(null, $m, 1)->translatedFormat('F')])->all()" />
                </x-field>

                <x-field name="live_from" :label="__('Still moving to its system? Live from')"
                         :hint="__('Leave empty if it already keeps its books in its system. Until this date it is not checked against your rules.')">
                    <x-input name="live_from" type="date" :value="$institution->live_from?->format('Y-m-d')" />
                </x-field>
            </div>

            <label class="mt-4 flex items-center gap-2 text-sm text-ink-soft">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $institution->is_active ?? true))
                       class="rounded border-rule-strong">
                {{ __('Active: it is expected to send reports') }}
            </label>
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                {{ $institution->exists ? __('Save changes') : __('Add institution') }}
            </button>
            <a href="{{ $institution->exists ? manage_route('institutions.show', $institution) : manage_route('institutions.index') }}"
               class="text-sm text-ink-muted hover:underline">{{ __('Cancel') }}</a>
        </div>
    </form>

@endsection
