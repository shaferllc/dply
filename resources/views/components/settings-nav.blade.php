@props([
    'variant' => 'sidebar',
])

@php
    /** @var \App\Models\User|null $user */
    $user = auth()->user();
    $isTop = $variant === 'top';
    $navBase = $isTop
        ? 'inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors'
        : 'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors';
    $navOn = 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm';
    $navOff = 'text-brand-moss border border-transparent hover:bg-brand-sand/40 hover:text-brand-ink';
    $navIcon = 'h-5 w-5 shrink-0 opacity-90';
    $accountNavClass = $isTop ? 'mt-3 flex flex-wrap gap-2' : 'mt-4 space-y-0.5';
    $guidesNavClass = $isTop ? 'mt-2 flex flex-wrap gap-2' : 'mt-2 space-y-0.5';

    $accountDropdownActive = request()->routeIs(
        'profile.edit',
        'profile.delete-account',
        'profile.referrals',
        'profile.security',
        'two-factor.setup',
        'profile.source-control',
        'profile.ssh-keys',
        'profile.api-keys',
        'profile.backup-configurations',
        'profile.notification-channels',
        'organizations.index',
        'organizations.create',
    );
    $guidesDropdownActive = request()->routeIs(
        'docs.create-first-server',
        'docs.connect-provider',
        'docs.index',
    ) || (
        request()->routeIs('docs.markdown')
        && in_array((string) request()->route('slug'), ['source-control', 'org-roles-and-limits'], true)
    );

    $dropdownTriggerBase = 'inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors border';
@endphp

<div
    data-settings-nav-layout="{{ $variant }}"
    @class(['dply-surface-nav', 'w-full overflow-visible' => $isTop])
>
    @if ($isTop)
        {{-- Match site-header: avoid overflow-x:auto on the nav row — it clips dropdown panels. --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            <div class="min-w-0 min-h-0 flex-1 overflow-visible sm:shrink">
                <div
                    class="flex flex-wrap items-center gap-2 sm:flex-nowrap"
                    role="navigation"
                    aria-label="{{ __('Settings and guides') }}"
                >
                    <a
                        href="{{ route('settings.profile') }}"
                        wire:navigate
                        @class([$navBase, request()->routeIs('settings.index', 'settings.profile', 'settings.servers') ? $navOn : $navOff])
                    >
                        <x-heroicon-o-adjustments-horizontal class="{{ $navIcon }}" aria-hidden="true" />
                        {{ __('Preferences') }}
                    </a>

                    <x-dropdown align="left" width="w-72" contentClasses="py-1.5 max-h-[min(70vh,28rem)] overflow-y-auto">
                        <x-slot name="trigger">
                            <button
                                type="button"
                                @class([
                                    $dropdownTriggerBase,
                                    $accountDropdownActive ? $navOn : $navOff,
                                ])
                                aria-haspopup="true"
                            >
                                <x-heroicon-o-user-circle class="{{ $navIcon }}" aria-hidden="true" />
                                {{ __('Account') }}
                                <x-heroicon-m-chevron-down class="ms-0.5 h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            <x-dropdown-link :href="route('profile.edit')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-user-circle class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Profile') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('profile.referrals')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-gift class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Referrals') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('profile.security')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-shield-check class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Security') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('profile.source-control')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-code-bracket-square class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Source control') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('profile.ssh-keys')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-key class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('SSH keys') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('profile.api-keys')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-bolt class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('API keys') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('profile.backup-configurations')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-archive-box class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Backup configurations') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('profile.notification-channels')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-bell-alert class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Notification channels') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('organizations.index')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-building-office-2 class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Organizations') }}
                            </x-dropdown-link>
                        </x-slot>
                    </x-dropdown>

                    <x-dropdown align="left" width="w-72" contentClasses="py-1.5 max-h-[min(70vh,28rem)] overflow-y-auto">
                        <x-slot name="trigger">
                            <button
                                type="button"
                                @class([
                                    $dropdownTriggerBase,
                                    $guidesDropdownActive ? $navOn : $navOff,
                                ])
                                aria-haspopup="true"
                            >
                                <x-heroicon-o-book-open class="{{ $navIcon }}" aria-hidden="true" />
                                {{ __('Guides') }}
                                <x-heroicon-m-chevron-down class="ms-0.5 h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            <x-dropdown-link :href="route('docs.create-first-server')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-server class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Create your first server') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('docs.connect-provider')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-cloud class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Connect a provider') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('docs.markdown', ['slug' => 'source-control'])" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-code-bracket-square class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Source control & deploys') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('docs.markdown', ['slug' => 'org-roles-and-limits'])" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-user-group class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Roles & plan limits') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('docs.index')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-rectangle-stack class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('All docs') }}
                            </x-dropdown-link>
                        </x-slot>
                    </x-dropdown>
                </div>
            </div>
            @if ($user)
                <p
                    class="shrink-0 font-semibold text-brand-ink truncate text-sm sm:max-w-[min(100%,24rem)] sm:text-right"
                    title="{{ $user->name }}"
                >
                    {{ $user->name }}
                </p>
            @endif
        </div>
    @else
        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Settings') }}</p>
        @if ($user)
            <p class="mt-1 font-semibold text-brand-ink truncate" title="{{ $user->name }}">{{ $user->name }}</p>
        @endif

        <nav class="{{ $accountNavClass }}" aria-label="{{ __('Account settings') }}">
            <a
                href="{{ route('settings.profile') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('settings.index', 'settings.profile', 'settings.servers') ? $navOn : $navOff])
            >
                <x-heroicon-o-adjustments-horizontal class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('Preferences') }}
            </a>
            <a
                href="{{ route('profile.edit') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('profile.edit', 'profile.delete-account') ? $navOn : $navOff])
            >
                <x-heroicon-o-user-circle class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('Profile') }}
            </a>
            <a
                href="{{ route('profile.referrals') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('profile.referrals') ? $navOn : $navOff])
            >
                <x-heroicon-o-gift class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('Referrals') }}
            </a>
            <a
                href="{{ route('profile.security') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('profile.security', 'two-factor.setup') ? $navOn : $navOff])
            >
                <x-heroicon-o-shield-check class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('Security') }}
            </a>
            <a
                href="{{ route('profile.source-control') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('profile.source-control') ? $navOn : $navOff])
            >
                <x-heroicon-o-code-bracket-square class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('Source control') }}
            </a>
            <a
                href="{{ route('profile.ssh-keys') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('profile.ssh-keys') ? $navOn : $navOff])
            >
                <x-heroicon-o-key class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('SSH keys') }}
            </a>
            <a
                href="{{ route('profile.api-keys') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('profile.api-keys') ? $navOn : $navOff])
            >
                <x-heroicon-o-bolt class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('API keys') }}
            </a>
            <a
                href="{{ route('profile.backup-configurations') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('profile.backup-configurations') ? $navOn : $navOff])
            >
                <x-heroicon-o-archive-box class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('Backup configurations') }}
            </a>
            <a
                href="{{ route('profile.notification-channels') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('profile.notification-channels') ? $navOn : $navOff])
            >
                <x-heroicon-o-bell-alert class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('Notification channels') }}
            </a>
            <a
                href="{{ route('organizations.index') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('organizations.index', 'organizations.create') ? $navOn : $navOff])
            >
                <x-heroicon-o-building-office-2 class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('Organizations') }}
            </a>
        </nav>

        <div class="mt-4 border-t border-brand-ink/10 pt-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Guides') }}</p>
            <nav class="{{ $guidesNavClass }}" aria-label="{{ __('Guides') }}">
                <a
                    href="{{ route('docs.create-first-server') }}"
                    wire:navigate
                    @class([$navBase, request()->routeIs('docs.create-first-server') ? $navOn : $navOff])
                >
                    <x-heroicon-o-server class="{{ $navIcon }}" aria-hidden="true" />
                    {{ __('Create your first server') }}
                </a>
                <a
                    href="{{ route('docs.connect-provider') }}"
                    wire:navigate
                    @class([$navBase, request()->routeIs('docs.connect-provider') ? $navOn : $navOff])
                >
                    <x-heroicon-o-cloud class="{{ $navIcon }}" aria-hidden="true" />
                    {{ __('Connect a provider') }}
                </a>
                <a
                    href="{{ route('docs.markdown', ['slug' => 'source-control']) }}"
                    wire:navigate
                    @class([$navBase, request()->routeIs('docs.markdown') && request()->route('slug') === 'source-control' ? $navOn : $navOff])
                >
                    <x-heroicon-o-code-bracket-square class="{{ $navIcon }}" aria-hidden="true" />
                    {{ __('Source control & deploys') }}
                </a>
                <a
                    href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}"
                    wire:navigate
                    @class([$navBase, request()->routeIs('docs.markdown') && request()->route('slug') === 'org-roles-and-limits' ? $navOn : $navOff])
                >
                    <x-heroicon-o-user-group class="{{ $navIcon }}" aria-hidden="true" />
                    {{ __('Roles & plan limits') }}
                </a>
                <a
                    href="{{ route('docs.index') }}"
                    wire:navigate
                    @class([$navBase, request()->routeIs('docs.index') ? $navOn : $navOff])
                >
                    <x-heroicon-o-rectangle-stack class="{{ $navIcon }}" aria-hidden="true" />
                    {{ __('All docs') }}
                </a>
            </nav>
        </div>
    @endif
</div>
