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
        'settings.profile',
        'settings.servers',
        'profile.delete-account',
        'profile.referrals',
        'profile.security',
        'two-factor.setup',
        'profile.source-control',
        'profile.ssh-keys',
        'profile.api-keys',
        'profile.cli',
        'profile.backup-configurations',
        'profile.notification-channels',
    );

    // Organizations is its own top-level menu item (peer to Account / Guides),
    // and the menu lists every organization the user belongs to.
    $organizations = $user
        ? $user->organizations()->orderBy('organizations.name')->get()
        : collect();
    $currentOrgId = session('current_organization_id');
    $organizationsNavActive = request()->routeIs('organizations.*', 'billing.*', 'subscription.*', 'teams.notification-channels');
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
                    {{-- "Preferences" pill removed after the /profile merge:
                         it pointed at the same /settings/profile URL the Account
                         dropdown's "Profile" entry already covers. --}}
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
                            <x-dropdown-link :href="route('profile.cli')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-command-line class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('CLI') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('profile.notification-channels')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-bell-alert class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Notification channels') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('settings.profile')" wire:navigate>
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
                        </x-slot>
                    </x-dropdown>

                    <x-dropdown align="left" width="w-72" contentClasses="py-1.5 max-h-[min(70vh,28rem)] overflow-y-auto">
                        <x-slot name="trigger">
                            <button
                                type="button"
                                @class([
                                    $dropdownTriggerBase,
                                    $organizationsNavActive ? $navOn : $navOff,
                                ])
                                aria-haspopup="true"
                            >
                                <x-heroicon-o-building-office-2 class="{{ $navIcon }}" aria-hidden="true" />
                                {{ __('Organizations') }}
                                <x-heroicon-m-chevron-down class="ms-0.5 h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            <x-dropdown-link :href="route('organizations.index')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-rectangle-stack class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('All organizations') }}
                            </x-dropdown-link>
                            @if ($organizations->isNotEmpty())
                                <div class="mx-2 my-1.5 border-t border-brand-ink/8" role="presentation"></div>
                                @foreach ($organizations as $org)
                                    <x-dropdown-link :href="route('organizations.show', $org)" wire:navigate>
                                        <x-slot name="icon">
                                            <x-heroicon-o-building-office-2 class="h-[1.15rem] w-[1.15rem]" />
                                        </x-slot>
                                        <span class="flex items-center justify-between gap-2">
                                            <span class="truncate">{{ $org->name }}</span>
                                            @if ($currentOrgId == $org->id)
                                                <span class="inline-flex shrink-0 items-center rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Current') }}</span>
                                            @endif
                                        </span>
                                    </x-dropdown-link>
                                @endforeach
                            @endif
                            <div class="mx-2 my-1.5 border-t border-brand-ink/8" role="presentation"></div>
                            <x-dropdown-link :href="route('organizations.create')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-plus class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('New organization') }}
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
                            <x-dropdown-link :href="route('docs.index')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-rectangle-stack class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('All docs') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('docs.connect-provider')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-cloud class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Connect a provider') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('docs.create-first-server')" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-server class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Create your first server') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('docs.markdown', ['slug' => 'org-roles-and-limits'])" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-user-group class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Roles & plan limits') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('docs.markdown', ['slug' => 'source-control'])" wire:navigate>
                                <x-slot name="icon">
                                    <x-heroicon-o-code-bracket-square class="h-[1.15rem] w-[1.15rem]" />
                                </x-slot>
                                {{ __('Source control & deploys') }}
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
        {{-- Identity card matches the family's section-header pattern:
             sage icon tile + eyebrow + the user's display name. Reads as
             "you're managing these settings for this account" rather than
             a bare SETTINGS label. --}}
        <div class="flex items-start gap-3 border-b border-brand-ink/10 pb-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Settings') }}</p>
                @if ($user)
                    <p class="mt-0.5 truncate text-sm font-semibold text-brand-ink" title="{{ $user->name }}">{{ $user->name }}</p>
                    <p class="mt-0.5 truncate text-[11px] text-brand-mist" title="{{ $user->email }}">{{ $user->email }}</p>
                @endif
            </div>
        </div>

        <p class="mt-4 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Account') }}</p>
        <nav class="{{ $accountNavClass }}" aria-label="{{ __('Account settings') }}">
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
                href="{{ route('profile.cli') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('profile.cli') ? $navOn : $navOff])
            >
                <x-heroicon-o-command-line class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('CLI') }}
            </a>
            <a
                href="{{ route('profile.notification-channels') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('profile.notification-channels') ? $navOn : $navOff])
            >
                <x-heroicon-o-bell-alert class="{{ $navIcon }}" aria-hidden="true" />
                {{ __('Notification channels') }}
            </a>
            {{-- Profile is the merged page (identity + preferences + sessions
                 + danger zone). The separate "Preferences" link is gone since
                 it pointed to the same URL after the /profile merge. --}}
            <a
                href="{{ route('settings.profile') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('settings.index', 'settings.profile', 'settings.servers', 'profile.delete-account') ? $navOn : $navOff])
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
        </nav>

        <div class="mt-5 border-t border-brand-ink/10 pt-4">
            <div class="flex items-center justify-between gap-2">
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Organizations') }}</p>
                <a
                    href="{{ route('organizations.create') }}"
                    wire:navigate
                    class="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-sage hover:text-brand-ink"
                    title="{{ __('New organization') }}"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('New') }}
                </a>
            </div>
            <nav class="{{ $guidesNavClass }}" aria-label="{{ __('Organizations') }}">
                <a
                    href="{{ route('organizations.index') }}"
                    wire:navigate
                    @class([$navBase, request()->routeIs('organizations.index') ? $navOn : $navOff])
                >
                    <x-heroicon-o-rectangle-stack class="{{ $navIcon }}" aria-hidden="true" />
                    {{ __('All organizations') }}
                </a>
                @foreach ($organizations as $org)
                    <a
                        href="{{ route('organizations.show', $org) }}"
                        wire:navigate
                        @class([$navBase, request()->routeIs('organizations.show') && (string) request()->route('organization')?->getKey() === (string) $org->getKey() ? $navOn : $navOff])
                    >
                        <x-heroicon-o-building-office-2 class="{{ $navIcon }}" aria-hidden="true" />
                        <span class="flex min-w-0 flex-1 items-center justify-between gap-2">
                            <span class="truncate">{{ $org->name }}</span>
                            @if ($currentOrgId == $org->id)
                                <span class="inline-flex shrink-0 items-center rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Current') }}</span>
                            @endif
                        </span>
                    </a>
                @endforeach
            </nav>
        </div>

        <div class="mt-5 border-t border-brand-ink/10 pt-4">
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Guides') }}</p>
            <nav class="{{ $guidesNavClass }}" aria-label="{{ __('Guides') }}">
                <a
                    href="{{ route('docs.index') }}"
                    wire:navigate
                    @class([$navBase, request()->routeIs('docs.index') ? $navOn : $navOff])
                >
                    <x-heroicon-o-rectangle-stack class="{{ $navIcon }}" aria-hidden="true" />
                    {{ __('All docs') }}
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
                    href="{{ route('docs.create-first-server') }}"
                    wire:navigate
                    @class([$navBase, request()->routeIs('docs.create-first-server') ? $navOn : $navOff])
                >
                    <x-heroicon-o-server class="{{ $navIcon }}" aria-hidden="true" />
                    {{ __('Create your first server') }}
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
                    href="{{ route('docs.markdown', ['slug' => 'source-control']) }}"
                    wire:navigate
                    @class([$navBase, request()->routeIs('docs.markdown') && request()->route('slug') === 'source-control' ? $navOn : $navOff])
                >
                    <x-heroicon-o-code-bracket-square class="{{ $navIcon }}" aria-hidden="true" />
                    {{ __('Source control & deploys') }}
                </a>
            </nav>
        </div>
    @endif
</div>
