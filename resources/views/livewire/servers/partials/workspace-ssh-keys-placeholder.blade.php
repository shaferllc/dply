{{--
    Lazy-load skeleton for SSH keys. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and section stubs).

    Receives $skeletonTab — the tab ?tab= asked for, seeded by placeholder().
    Named to avoid colliding with the component's own $ssh_workspace_tab, which
    SupportLazyLoading overwrites when it regenerates this view.
--}}
@php $skeletonTab = $skeletonTab ?? 'keys'; @endphp

<x-server-workspace-layout
    :server="$server"
    active="ssh"
    :title="__('SSH keys')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading SSH keys…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-key"
            :title="__('SSH keys')"
            :note="__('Authorize keys, preview drift, audit changes, and sync authorized_keys.')"
            class="border-b border-brand-ink/10"
        />

        {{-- The requested tab is highlighted, not always the first, so a
             deep-linked ?tab= doesn't paint Keys and then jump. --}}
        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach (['keys' => __('Keys'), 'preview' => __('Drift'), 'advanced' => __('Advanced'), 'activity' => __('Activity'), 'notifications' => __('Notifications')] as $key => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $key === $skeletonTab,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $key !== $skeletonTab,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        @include('livewire.servers.partials.ssh-keys._tab-skeleton', ['tab' => $skeletonTab])
    </section>
</x-server-workspace-layout>
