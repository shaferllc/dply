@php
    /** @var \App\Models\User|null $user */
    $user = auth()->user();
    $navBase = 'block rounded-lg px-3 py-2 text-sm font-medium transition-colors';
    $navOn = 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm';
    $navOff = 'text-brand-moss border border-transparent hover:bg-brand-sand/40 hover:text-brand-ink';
@endphp

<div class="dply-surface-nav">
    <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Settings') }}</p>
    @if ($user)
        <p class="mt-1 font-semibold text-brand-ink truncate" title="{{ $user->name }}">{{ $user->name }}</p>
    @endif

    <nav class="mt-4 space-y-0.5" aria-label="{{ __('Account settings') }}">
        <a
            href="{{ route('settings.profile') }}"
            wire:navigate
            @class([$navBase, request()->routeIs('settings.index', 'settings.profile', 'settings.servers') ? $navOn : $navOff])
        >
            {{ __('Preferences') }}
        </a>
        <a
            href="{{ route('profile.edit') }}"
            wire:navigate
            @class([$navBase, request()->routeIs('profile.edit', 'profile.delete-account') ? $navOn : $navOff])
        >
            {{ __('Profile') }}
        </a>
        <a
            href="{{ route('profile.referrals') }}"
            wire:navigate
            @class([$navBase, request()->routeIs('profile.referrals') ? $navOn : $navOff])
        >
            {{ __('Referrals') }}
        </a>
        <a
            href="{{ route('profile.security') }}"
            wire:navigate
            @class([$navBase, request()->routeIs('profile.security', 'two-factor.setup') ? $navOn : $navOff])
        >
            {{ __('Security') }}
        </a>
        <a
            href="{{ route('profile.source-control') }}"
            wire:navigate
            @class([$navBase, request()->routeIs('profile.source-control') ? $navOn : $navOff])
        >
            {{ __('Source control') }}
        </a>
        <a
            href="{{ route('profile.ssh-keys') }}"
            wire:navigate
            @class([$navBase, request()->routeIs('profile.ssh-keys') ? $navOn : $navOff])
        >
            {{ __('SSH keys') }}
        </a>
        <a
            href="{{ route('profile.api-keys') }}"
            wire:navigate
            @class([$navBase, request()->routeIs('profile.api-keys') ? $navOn : $navOff])
        >
            {{ __('API keys') }}
        </a>
        <a
            href="{{ route('profile.backup-configurations') }}"
            wire:navigate
            @class([$navBase, request()->routeIs('profile.backup-configurations') ? $navOn : $navOff])
        >
            {{ __('Backup configurations') }}
        </a>
        <a
            href="{{ route('profile.notification-channels') }}"
            wire:navigate
            @class([$navBase, request()->routeIs('profile.notification-channels') ? $navOn : $navOff])
        >
            {{ __('Notification channels') }}
        </a>
        <a
            href="{{ route('organizations.index') }}"
            wire:navigate
            @class([$navBase, request()->routeIs('organizations.index', 'organizations.create') ? $navOn : $navOff])
        >
            {{ __('Organizations') }}
        </a>
    </nav>

    <div class="mt-4 border-t border-brand-ink/10 pt-4">
        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Guides') }}</p>
        <nav class="mt-2 space-y-0.5" aria-label="{{ __('Guides') }}">
            <a
                href="{{ route('docs.source-control') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('docs.source-control') ? $navOn : $navOff])
            >
                {{ __('Source control & deploys') }}
            </a>
            <a
                href="{{ route('docs.index') }}"
                wire:navigate
                @class([$navBase, request()->routeIs('docs.index') ? $navOn : $navOff])
            >
                {{ __('All docs') }}
            </a>
        </nav>
    </div>
</div>

<a
    href="{{ route('dashboard') }}"
    wire:navigate
    class="mt-4 inline-flex text-sm text-brand-moss hover:text-brand-ink"
>
    ← {{ __('Dashboard') }}
</a>
