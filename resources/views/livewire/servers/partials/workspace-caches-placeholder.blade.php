{{--
    Lazy-load skeleton for Caches. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and the Overview body), and shares that body with
    the tab-switch skeleton so the two can't drift apart.
--}}
@php
    $cachesRole = (string) ($server->meta['server_role'] ?? '');
    $cachesTitle = match ($cachesRole) {
        'redis' => __('Redis'),
        'valkey' => __('Valkey'),
        default => __('Caches'),
    };
@endphp
<x-server-workspace-layout
    :server="$server"
    active="caches"
    :title="$cachesTitle"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading caches…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-bolt"
            :title="$cachesTitle"
            :note="__('Install and manage cache engines on this server — Redis, Valkey, Memcached, KeyDB, and Dragonfly. Multiple engines can run side-by-side.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
            @foreach ([__('Overview'), __('Redis'), __('Valkey'), __('Advanced')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-[11px] font-semibold leading-none',
                    'bg-brand-ink text-brand-cream shadow-sm' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        @include('livewire.servers.partials.cache._tab-skeleton', ['tab' => 'overview', 'rows' => 4])
    </section>
</x-server-workspace-layout>
