{{--
    Lazy-load skeleton for System users. Mirrors the merged page (hide-hero +
    single card with identity, tabs, and the Accounts panel), and shares the
    panel body with the tab-switch skeleton so the two can't drift apart.
--}}
<x-server-workspace-layout
    :server="$server"
    active="system-users"
    :title="__('System users')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading system users…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-user-group"
            :title="__('System users')"
            :note="__('Linux accounts on this server. Sites pick from these for their file owner / PHP-FPM pool user.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
            @foreach ([__('Accounts'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-[11px] font-semibold leading-none',
                    'bg-brand-ink text-brand-cream shadow-sm' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        @include('livewire.servers.partials.system-users._tab-skeleton', ['tab' => 'accounts'])
    </section>
</x-server-workspace-layout>
