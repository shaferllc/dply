{{--
    Lazy-load skeleton for Firewall. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and rules stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="firewall"
    :title="__('Firewall')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading firewall…') }}</span>

        {{-- The same dense head the real page renders — keep the two in step or
             the card resizes when the render swaps in. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-shield-check"
            :title="__('Firewall')"
            :note="__('Manage basic UFW access on the host with rules, presets, templates, apply, status, and recent history.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Rules'), __('Templates'), __('Activity'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        {{-- Body reuses the same per-tab skeleton the tab switch renders, so
             the two can't drift. Rules is the default landing tab. --}}
        @include('livewire.servers.partials.firewall._tab-skeleton', ['tab' => 'rules'])

    </section>
</x-server-workspace-layout>
