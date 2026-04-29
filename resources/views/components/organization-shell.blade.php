@props([
    'organization',
    'section' => 'overview',
])

@php
    $org = $organization;
    $is = fn (string $key): bool => $section === $key;
    $link = fn (string $key) => $is($key)
        ? 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm'
        : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink border border-transparent';
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
                    @class(['block rounded-lg px-3 py-2 text-sm font-medium transition-colors', $link('overview')])
                >
                    {{ __('Overview') }}
                </a>
                @can('update', $org)
                    <a
                        href="{{ route('billing.show', $org) }}"
                        wire:navigate
                        @class(['block rounded-lg px-3 py-2 text-sm font-medium transition-colors', $link('billing')])
                    >
                        {{ __('Billing & plan') }}
                    </a>
                    <a
                        href="{{ route('billing.invoices', $org) }}"
                        wire:navigate
                        @class(['block rounded-lg px-3 py-2 text-sm font-medium transition-colors', $link('invoices')])
                    >
                        {{ __('Invoices') }}
                    </a>
                @endcan
                @can('viewNotificationChannels', $org)
                    <a
                        href="{{ route('organizations.notification-channels', $org) }}"
                        wire:navigate
                        @class(['block rounded-lg px-3 py-2 text-sm font-medium transition-colors', $link('notifications')])
                    >
                        {{ __('Notification channels') }}
                    </a>
                @endcan
                @can('viewAny', \App\Models\ProviderCredential::class)
                    <a
                        href="{{ route('organizations.credentials', $org) }}"
                        wire:navigate
                        @class(['block rounded-lg px-3 py-2 text-sm font-medium transition-colors', $link('providers')])
                    >
                        {{ __('Server providers') }}
                    </a>
                @endcan
                @can('view', $org)
                    <a
                        href="{{ route('organizations.webserver-templates', $org) }}"
                        wire:navigate
                        @class(['block rounded-lg px-3 py-2 text-sm font-medium transition-colors', $link('webserver')])
                    >
                        {{ __('Webserver templates') }}
                    </a>
                @endcan
            </nav>
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
