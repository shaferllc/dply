@props([
    'organization',
    'section' => 'overview',
])

@php
    $org = $organization;
    $is = fn (string $key): bool => $section === $key;
    $navBase = 'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors';
    $link = fn (string $key) => $is($key)
        ? 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm'
        : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink border border-transparent';
    $docNavOn = 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm';
    $docNavOff = 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink border border-transparent';
    $ni = 'h-[1.125rem] w-[1.125rem] shrink-0 opacity-90';
@endphp

<div class="lg:grid lg:grid-cols-12 lg:gap-10">
    <aside class="lg:col-span-3 mb-8 lg:mb-0 shrink-0">
        <div class="dply-surface-nav">
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Organization') }}</p>
            <p class="mt-1 font-semibold text-brand-ink truncate" title="{{ $org->name }}">{{ $org->name }}</p>
            <nav class="mt-4 space-y-0.5" aria-label="{{ __('Organization navigation') }}">
                <a
                    href="{{ route('organizations.show', $org) }}"
                    wire:navigate
                    @class([$navBase, $link('overview')])
                >
                    <x-heroicon-o-squares-2x2 class="{{ $ni }}" aria-hidden="true" />
                    {{ __('Overview') }}
                </a>
                <a
                    href="{{ route('organizations.members', $org) }}"
                    wire:navigate
                    @class([$navBase, $link('members')])
                >
                    <x-heroicon-o-users class="{{ $ni }}" aria-hidden="true" />
                    {{ __('Members') }}
                </a>
                <a
                    href="{{ route('organizations.teams', $org) }}"
                    wire:navigate
                    @class([$navBase, $link('teams')])
                >
                    <x-heroicon-o-rectangle-group class="{{ $ni }}" aria-hidden="true" />
                    {{ __('Teams') }}
                </a>
                @if ($org->hasAdminAccess(auth()->user()))
                    <a
                        href="{{ route('organizations.activity', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('activity')])
                    >
                        <x-heroicon-o-clock class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Activity') }}
                    </a>
                    <a
                        href="{{ route('organizations.automation', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('automation')])
                    >
                        <x-heroicon-o-bolt class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Automation') }}
                    </a>
                @endif
                @can('update', $org)
                    <a
                        href="{{ route('billing.show', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('billing')])
                    >
                        <x-heroicon-o-credit-card class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Billing & plan') }}
                    </a>
                    <a
                        href="{{ route('billing.invoices', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('invoices')])
                    >
                        <x-heroicon-o-document-text class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Invoices') }}
                    </a>
                @endcan
                @can('viewNotificationChannels', $org)
                    <a
                        href="{{ route('organizations.notification-channels', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('notifications')])
                    >
                        <x-heroicon-o-bell class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Notification channels') }}
                    </a>
                @endcan
                @can('viewAny', \App\Models\ProviderCredential::class)
                    <a
                        href="{{ route('organizations.credentials', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('providers')])
                    >
                        <x-heroicon-o-server class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Server providers') }}
                    </a>
                @endcan
                @can('view', $org)
                    <a
                        href="{{ route('organizations.webserver-templates', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('webserver')])
                    >
                        <x-heroicon-o-server-stack class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Webserver templates') }}
                    </a>
                @endcan
            </nav>
            <div class="mt-4 border-t border-brand-ink/10 pt-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Guides') }}</p>
                <nav class="mt-2 space-y-0.5" aria-label="{{ __('Documentation guides') }}">
                    <a
                        href="{{ route('docs.connect-provider') }}"
                        wire:navigate
                        @class([$navBase, request()->routeIs('docs.connect-provider') ? $docNavOn : $docNavOff])
                    >
                        <x-heroicon-o-cloud class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Connect a provider') }}
                    </a>
                    <a
                        href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}"
                        wire:navigate
                        @class([$navBase, request()->routeIs('docs.markdown') && request()->route('slug') === 'org-roles-and-limits' ? $docNavOn : $docNavOff])
                    >
                        <x-heroicon-o-user-group class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Roles & plan limits') }}
                    </a>
                    <a
                        href="{{ route('docs.index') }}"
                        wire:navigate
                        @class([$navBase, request()->routeIs('docs.index') ? $docNavOn : $docNavOff])
                    >
                        <x-heroicon-o-rectangle-stack class="{{ $ni }}" aria-hidden="true" />
                        {{ __('All docs') }}
                    </a>
                </nav>
            </div>
        </div>
        <a
            href="{{ route('organizations.index') }}"
            wire:navigate
            class="mt-4 inline-flex text-sm text-brand-moss hover:text-brand-ink"
        >
            ← {{ __('All organizations') }}
        </a>
    </aside>
    <div {{ $attributes->merge(['class' => 'lg:col-span-9 min-w-0']) }}>
        {{ $slot }}
    </div>
</div>
