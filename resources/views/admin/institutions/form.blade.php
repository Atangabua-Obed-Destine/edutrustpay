@extends('layouts.console')

@section('title', $institution->exists ? __('Edit :name', ['name' => $institution->name]) : __('Enrol an institution'))

@section('content')

    <x-breadcrumb :items="array_filter([
        __('Institutions') => route('admin.institutions.index'),
        ($institution->exists ? $institution->name : null) => $institution->exists ? route('admin.institutions.show', $institution) : null,
        ($institution->exists ? __('Edit') : __('New')) => null,
    ])" />

    <form method="POST"
          action="{{ $institution->exists ? route('admin.institutions.update', $institution) : route('admin.institutions.store') }}"
          class="max-w-3xl space-y-6">
        @csrf
        @if ($institution->exists) @method('PUT') @endif

        <x-card :title="__('Identity')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="name" :label="__('Name')" required class="sm:col-span-2">
                    <x-input name="name" :value="$institution->name" required autofocus />
                </x-field>

                <x-field name="body_id" :label="__('Belongs to')" required>
                    <x-select name="body_id" :selected="$institution->body_id" :placeholder="__('— choose a body —')"
                              :options="$bodies->mapWithKeys(fn ($b) => [$b->id => $b->indented_name])->all()" />
                </x-field>

                <x-field name="type" :label="__('Type')" required
                         :hint="__('Decides which capabilities can ever apply. A business has no enrolment, and the console shows that as “not applicable” rather than a gap.')">
                    <x-select name="type" :selected="$institution->type" :options="[
                        'school_basic' => __('School — primary or secondary'),
                        'school_higher' => __('School — higher education'),
                        'business' => __('Business — hospital, pharmacy, shop'),
                    ]" />
                </x-field>

                <x-field name="system" :label="__('Runs on')" required
                         :hint="__('Which codebase this institution reports from. It determines what the client can produce.')">
                    <x-select name="system" :selected="$institution->system" :options="[
                        'edutrustshsm' => 'edutrustshsm — ' . __('primary and secondary schools'),
                        'paxhitestbed' => 'paxhitestbed — ' . __('higher education'),
                        'posinnova' => 'posinnova — ' . __('hospitals, pharmacies, shops'),
                    ]" />
                </x-field>

                <x-field name="slug" :label="__('Slug')" :hint="__('Optional. Generated from the name if blank.')">
                    <x-input name="slug" :value="$institution->slug" />
                </x-field>

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

        <x-card :title="__('Connection')">
            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Push is the only mode that exists. The tunnel is on the
                     roadmap and is offered here as "not yet available" rather
                     than hidden, because an operator who has been promised
                     drill-down should be able to see that it is coming and
                     that it is not here. --}}
                <x-field name="connectivity_mode" :label="__('Connectivity')" required
                         :hint="__('Choose Push. Reports are sent OUT from the institution over HTTPS and nothing reaches in — the only arrangement daily reporting may depend on. A tunnel would add live drill-down on top; it is not built yet.')">
                    <select name="connectivity_mode" id="connectivity_mode"
                            class="w-full rounded border border-rule-strong bg-surface px-3 py-2 text-sm text-ink
                                   focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                        <option value="push" @selected(old('connectivity_mode', $institution->connectivity_mode ?? 'push') === 'push')>
                            {{ __('Push over outbound HTTPS') }}
                        </option>
                        <option value="tunnel" disabled>
                            {{ __('Push, plus a tunnel for drill-down — not yet available') }}
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
                <x-field name="contract_version" :label="__('Contract version')" required
                         :hint="__('What this institution reports in. Updated automatically to whatever it actually sends, so during a staged rollout this shows where each one has got to. :n version(s) exist so far.', ['n' => count(\EdutrustPay\Contract\ContractVersion::SUPPORTED)])">
                    <x-select name="contract_version"
                              :selected="$institution->contract_version ?? \EdutrustPay\Contract\ContractVersion::CURRENT"
                              :options="collect(\EdutrustPay\Contract\ContractVersion::SUPPORTED)
                                  ->mapWithKeys(fn ($v) => [$v => $v . ($v === \EdutrustPay\Contract\ContractVersion::CURRENT ? ' — ' . __('current') : '')])
                                  ->all()" />
                </x-field>

                <x-field name="endpoint" :label="__('Their system URL')" :hint="__('Optional. Used only to link out for line detail — EdutrustPay never reaches in.')" class="sm:col-span-2">
                    <x-input name="endpoint" type="url" :value="$institution->endpoint" placeholder="https://" />
                </x-field>

                <x-field name="not_reporting_days" :label="__('Silence threshold (days)')"
                         :hint="__('Blank inherits from the body, which inherits the platform default.')">
                    <x-input name="not_reporting_days" type="number" min="1" max="365" :value="$institution->not_reporting_days" />
                </x-field>

                {{-- The institution's OWN year, because its year-to-date
                     statement has to reconcile against the sheet its own bursar
                     works from. A school's year follows its terms; the body
                     that owns it may well keep calendar books. --}}
                <x-field name="fiscal_year_start_month" :label="__('Fiscal year starts')"
                         :hint="__('Blank inherits from the body. Set it where this institution\'s own year differs — a school year opening in October is not the archdiocese\'s January. Changes no figure, only which twelve months a year-to-date column adds up.')">
                    <x-select name="fiscal_year_start_month" :selected="$institution->fiscal_year_start_month"
                              :placeholder="__('— inherit from the body —')"
                              :options="collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Carbon\Carbon::create(null, $m, 1)->format('F')])->all()" />
                </x-field>
            </div>

            <label class="mt-4 flex items-center gap-2 text-sm text-ink-soft">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $institution->is_active ?? true))
                       class="rounded border-rule-strong">
                {{ __('Active') }}
            </label>
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                {{ $institution->exists ? __('Save changes') : __('Enrol institution') }}
            </button>
            <a href="{{ $institution->exists ? route('admin.institutions.show', $institution) : route('admin.institutions.index') }}"
               class="text-sm text-ink-muted hover:underline">{{ __('Cancel') }}</a>
        </div>
    </form>

@endsection
