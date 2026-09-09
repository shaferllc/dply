{{--
    Lazy-load skeleton for Webserver. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and overview stubs), and shares the panel body with
    the tab-switch skeleton so the two can't drift apart.
--}}
<x-server-workspace-layout
    :server="$server"
    active="webserver"
    :title="__('Webserver')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading webserver…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-globe-alt"
            :title="__('Webserver')"
            :note="__('Pick which webserver runs on this box. Switching reprovisions all sites under the new daemon, then service-swaps to :80.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
            @foreach ([__('Overview'), __('Change'), __('Health'), __('nginx'), __('Advanced')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-xs font-semibold leading-none',
                    'bg-brand-ink text-brand-cream shadow-sm' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        @include('livewire.servers.partials.webserver._tab-skeleton', ['tab' => 'overview'])
    </section>
</x-server-workspace-layout>
