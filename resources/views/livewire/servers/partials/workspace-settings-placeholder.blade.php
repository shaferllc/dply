{{--
    Lazy-load skeleton for Settings. Mirrors the merged page (hide-hero + single
    card with identity, category tabs, and section stubs). Destination section
    stays highlighted so URL-navigated sub-tabs feel stable while the body loads.
--}}
@php
    $tabs = $tabs ?? [];
    $section = $section ?? 'connection';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="settings"
    :title="__('Settings')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading settings…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-cog-8-tooth class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Settings') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Navigate through the tabs to manage different settings categories. Changes in each section are automatically saved.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Settings categories')" scroll class="!mb-0 w-full border-0 bg-transparent p-0 shadow-none">
                @foreach ($tabs as $slug => $meta)
                    <x-server-workspace-tab
                        as="a"
                        :id="'settings-tab-'.$slug"
                        :href="route('servers.settings', ['server' => $server, 'section' => $slug])"
                        wire:navigate
                        :active="$section === $slug"
                        :icon="! empty($meta['icon']) ? 'heroicon-o-'.$meta['icon'] : null"
                        :variant="$slug === 'danger' ? 'danger' : 'default'"
                    >
                        {{ __($meta['label']) }}
                    </x-server-workspace-tab>
                @endforeach
            </x-server-workspace-tablist>
        </div>

        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
                <span class="h-10 w-10 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-2.5 w-20 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-72 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
            <div class="space-y-4 px-5 py-6 sm:px-6">
                <div class="h-3 w-32 animate-pulse rounded bg-brand-ink/10"></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (range(1, 4) as $field)
                        <div class="space-y-2">
                            <div class="h-2.5 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-10 w-full animate-pulse rounded-lg bg-brand-ink/10"></div>
                        </div>
                    @endforeach
                </div>
                <div class="h-9 w-28 animate-pulse rounded-lg bg-brand-ink/10"></div>
            </div>
        </div>
    </section>
</x-server-workspace-layout>
