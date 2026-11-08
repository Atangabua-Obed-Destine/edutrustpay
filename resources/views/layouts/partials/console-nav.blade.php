<nav class="flex-1 space-y-6 px-3 py-5 text-sm">
    @unless ($isOperator)
    <div>
        <p class="px-2 pb-2 text-[11px] font-semibold uppercase tracking-wider text-white/45">{{ __('Oversight') }}</p>

        <x-nav-link :href="route('body.dashboard')" :active="request()->routeIs('body.dashboard')">
            {{ __('Overview') }}
        </x-nav-link>

        @can('compliance.view')
            <x-nav-link :href="route('body.compliance.index')" :active="request()->routeIs('body.compliance.*')">
                {{ __('Compliance') }}
            </x-nav-link>
        @endcan

        @can('finding.view')
            <x-nav-link :href="route('body.findings.index')" :active="request()->routeIs('body.findings.*')"
                        :badge="$openFindingCount ?? null">
                {{ __('To look into') }}
            </x-nav-link>
        @endcan

        @can('consolidation.view')
            <x-nav-link :href="route('body.consolidation')" :active="request()->routeIs('body.consolidation')">
                {{ __('Consolidated position') }}
            </x-nav-link>
        @endcan
    </div>

    <div>
        <p class="px-2 pb-2 text-[11px] font-semibold uppercase tracking-wider text-white/45">{{ __('Institutions') }}</p>

        @can('institution.view')
            <x-nav-link :href="route('body.institutions.index')" :active="request()->routeIs('body.institutions.*') || request()->routeIs('body.manage.institutions.*')">
                {{ __('All institutions') }}
            </x-nav-link>
        @endcan

        @can('daybook.compare')
            <x-nav-link :href="route('body.daybook.compare')" :active="request()->routeIs('body.daybook.*')">
                {{ __('Compare schools') }}
            </x-nav-link>
        @endcan

        @can('capability.view')
            <x-nav-link :href="route('body.capabilities')" :active="request()->routeIs('body.capabilities')">
                {{ __('Capability matrix') }}
            </x-nav-link>
        @endcan
    </div>

    @canany(['user.view', 'role.view'])
        <div>
            <p class="px-2 pb-2 text-[11px] font-semibold uppercase tracking-wider text-white/45">{{ __('Manage') }}</p>

            @can('user.view')
                <x-nav-link :href="route('body.manage.users.index')" :active="request()->routeIs('body.manage.users.*')">
                    {{ __('People') }}
                </x-nav-link>
            @endcan

            @can('role.view')
                <x-nav-link :href="route('body.manage.roles.index')" :active="request()->routeIs('body.manage.roles.*')">
                    {{ __('Roles') }}
                </x-nav-link>
            @endcan
        </div>
    @endcanany
    @endunless

    @if ($isOperator)
        <div>
            <p class="px-2 pb-2 text-[11px] font-semibold uppercase tracking-wider text-white/45">{{ __('Operator') }}</p>

            <x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">
                {{ __('Platform overview') }}
            </x-nav-link>
            <x-nav-link :href="route('admin.bodies.index')" :active="request()->routeIs('admin.bodies.*')">
                {{ __('Bodies') }}
            </x-nav-link>
            <x-nav-link :href="route('admin.institutions.index')" :active="request()->routeIs('admin.institutions.*')">
                {{ __('Enrolment') }}
            </x-nav-link>
            <x-nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                {{ __('People') }}
            </x-nav-link>
        </div>
    @endif
</nav>
