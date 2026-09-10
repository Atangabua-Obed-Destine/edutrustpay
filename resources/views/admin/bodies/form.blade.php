@extends('layouts.console')

@section('title', $body->exists ? __('Edit :name', ['name' => $body->name]) : __('New body'))

@section('content')

    <x-breadcrumb :items="array_filter([
        __('Bodies') => route('admin.bodies.index'),
        ($body->exists ? $body->name : null) => $body->exists ? route('admin.bodies.show', $body) : null,
        ($body->exists ? __('Edit') : __('New')) => null,
    ])" />

    <form method="POST" enctype="multipart/form-data"
          action="{{ $body->exists ? route('admin.bodies.update', $body) : route('admin.bodies.store') }}"
          class="max-w-3xl space-y-6">
        @csrf
        @if ($body->exists) @method('PUT') @endif

        <x-card :title="__('Identity')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="name" :label="__('Name')" required class="sm:col-span-2">
                    <x-input name="name" :value="$body->name" required autofocus />
                </x-field>

                {{-- A label, not a classification.

                     The suggestions are a convenience, not a vocabulary: this
                     platform will hold regional delegations, congregations,
                     trusts and school boards as readily as deaneries, and a
                     closed list would file every one of them as "other". --}}
                <x-field name="type" :label="__('Type')"
                         :hint="__('Optional, and free text — whatever this organisation actually calls itself. Shown as a caption; nothing depends on it.')">
                    <x-input name="type" :value="$body->type" list="body-types"
                             placeholder="{{ __('e.g. Archdiocese, Regional Delegation, Congregation') }}" />

                    <datalist id="body-types">
                        @foreach ([
                            __('Archdiocese'), __('Diocese'), __('Deanery'), __('Parish'),
                            __('Regional Delegation'), __('Divisional Delegation'),
                            __('Congregation'), __('School group'), __('Ministry'), __('Trust'),
                        ] as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>
                </x-field>

                <x-field name="parent_body_id" :label="__('Sits inside')"
                         :hint="__('Leave empty for a top-level body. A deanery sees its own institutions; the body above it sees everything beneath.')">
                    <x-select name="parent_body_id" :selected="$body->parent_body_id"
                              :placeholder="__('— top level —')"
                              :options="$parents->mapWithKeys(fn ($p) => [$p->id => $p->indented_name])->all()" />
                </x-field>

                <x-field name="slug" :label="__('Slug')" :hint="__('Optional. Generated from the name if left blank.')" class="sm:col-span-2">
                    <x-input name="slug" :value="$body->slug" />
                </x-field>

                <x-field name="description" :label="__('Description')" class="sm:col-span-2">
                    <textarea name="description" id="description" rows="2"
                              class="w-full rounded border border-rule-strong px-3 py-2 text-sm">{{ old('description', $body->description) }}</textarea>
                </x-field>
            </div>
        </x-card>

        <x-card :title="__('Contact')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="city" :label="__('City')"><x-input name="city" :value="$body->city" /></x-field>
                <x-field name="region" :label="__('Region')">
                    <x-select name="region" :selected="$body->region" :placeholder="__('—')" :options="collect([
                        'Centre','Littoral','West','Northwest','Southwest','North','Adamawa','East','South','Far North',
                    ])->mapWithKeys(fn ($r) => [$r => $r])->all()" />
                </x-field>
                <x-field name="country" :label="__('Country')" required><x-input name="country" :value="$body->country ?? 'Cameroon'" required /></x-field>
                <x-field name="phone" :label="__('Phone')"><x-input name="phone" :value="$body->phone" /></x-field>
                <x-field name="email" :label="__('Email')"><x-input name="email" type="email" :value="$body->email" /></x-field>
                <x-field name="website" :label="__('Website')"><x-input name="website" type="url" :value="$body->website" /></x-field>
            </div>
        </x-card>

        <x-card :title="__('Reporting')"
                :subtitle="__('How this body reads its institutions\' figures.')">
            <div class="grid gap-4 sm:grid-cols-3">
                {{-- This governs the CONSOLIDATED position — the body's own
                     year, across institutions that individually run different
                     ones. Each institution can set its own for its own
                     statement, and inherits this when it does not. --}}
                <x-field name="fiscal_year_start_month" :label="__('Fiscal year starts')"
                         :hint="__('This body\'s own year, used for the consolidated position and inherited by any institution that has not set its own. A view preference only: institutions always report calendar months, and the console assembles any fiscal year from them.')">
                    <x-select name="fiscal_year_start_month" :selected="$body->fiscal_year_start_month ?? 1"
                              :options="collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Carbon\Carbon::create(null, $m, 1)->format('F')])->all()" />
                </x-field>

                <x-field name="currency" :label="__('Currency')" required>
                    <x-input name="currency" :value="$body->currency ?? config('platform.currency')" maxlength="3" required />
                </x-field>

                <x-field name="not_reporting_days" :label="__('Silence threshold (days)')"
                         :hint="__('Blank uses the platform default of :days. A rural mission reaching a hotspot monthly and a city hospital on fibre should not share one.', ['days' => config('findings.rules.not_reporting.days')])">
                    <x-input name="not_reporting_days" type="number" min="1" max="365" :value="$body->not_reporting_days" />
                </x-field>
            </div>

            <label class="mt-4 flex items-center gap-2 text-sm text-ink-soft">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $body->is_active ?? true))
                       class="rounded border-rule-strong">
                {{ __('Active') }}
            </label>
        </x-card>

        {{-- Identity.

             An archdiocese is not a SaaS tenant. Its people know its crest, and
             a console carrying it reads as their own record rather than as
             somebody's software. Both fields are inherited down the tree, so a
             deanery normally sets neither and simply looks like its parent. --}}
        <x-card :title="__('Appearance')"
                :subtitle="__('Optional. A body inside another inherits both unless it sets its own.')">
            <div class="grid gap-6 sm:grid-cols-2">

                <x-field name="logo" :label="__('Crest')"
                         :hint="__('PNG, JPG, SVG or WebP, up to 512 KB. Shown in the sidebar and on the consolidated statement.')">
                    @if ($body->logo_path)
                        <div class="mb-2 flex items-center gap-3">
                            <img src="{{ $body->logoUrl() }}" alt=""
                                 class="h-12 w-12 rounded border border-rule bg-surface object-contain p-1">
                            <label class="flex items-center gap-2 text-xs text-ink-soft">
                                <input type="checkbox" name="remove_logo" value="1" class="rounded border-rule-strong">
                                {{ __('Remove it') }}
                            </label>
                        </div>
                    @endif

                    <input type="file" name="logo" id="logo" accept="image/*"
                           class="w-full rounded border border-rule-strong bg-surface px-3 py-2 text-sm text-ink-soft
                                  file:mr-3 file:rounded file:border-0 file:bg-surface-sunk file:px-3 file:py-1
                                  file:text-sm file:text-ink-soft">
                </x-field>

                <x-field name="accent_colour" :label="__('Accent colour')"
                         :hint="__('Used for the sidebar and the rule under each page title. Figures are never coloured by it.')">
                    <div class="flex items-center gap-3">
                        <input type="color" name="accent_colour" id="accent_colour"
                               value="{{ old('accent_colour', $body->accent_colour ?: $body->accent()) }}"
                               class="h-10 w-16 cursor-pointer rounded border border-rule-strong bg-surface p-1">
                        <span class="text-xs text-ink-muted">
                            {{ $body->accent_colour ? __('Set for this body.') : __('Currently inherited.') }}
                        </span>
                    </div>
                </x-field>
            </div>
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="rounded bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                {{ $body->exists ? __('Save changes') : __('Create body') }}
            </button>
            <a href="{{ $body->exists ? route('admin.bodies.show', $body) : route('admin.bodies.index') }}"
               class="text-sm text-ink-muted hover:underline">{{ __('Cancel') }}</a>
        </div>
    </form>

@endsection
