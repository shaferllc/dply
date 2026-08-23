@php
    $channelCount = $channels->count();
    $canAddChannel = $canManage && count($types) > 0;
    // Shell header: Add lives in the sand header only when the list already
    // has items. Empty state owns the single CTA so we never stack two Adds.
    $showShellAdd = $canAddChannel && $channelCount > 0;
@endphp

<div>
    @if (! empty($useOrgShell))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <x-organization-shell
                dense
                :organization="$organization"
                :section="$orgShellSection ?? 'notifications'"
                :title="$pageTitle"
                :description="$intro"
                icon="heroicon-o-bell"
                :breadcrumb="$breadcrumbs ?? null"
            >
                <x-slot:actions>
                    @if (! empty($showBulkAssign ?? false))
                        <a
                            href="{{ route('profile.notification-channels.bulk-assign') }}"
                            wire:navigate
                            class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-paper-airplane class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Bulk assign') }}
                        </a>
                    @endif
                    @if ($showShellAdd)
                        <button
                            type="button"
                            wire:click="openCreateChannelModal"
                            class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Add channel') }}
                        </button>
                    @endif
                </x-slot:actions>

                @include($contentPartial)
            </x-organization-shell>
        </div>
    @else
        <x-profile-shell
            dense
            :title="$pageTitle"
            :description="$intro"
            icon="heroicon-o-bell"
        >
            {{-- No "Back to profile": the breadcrumb already covers it. --}}
            <x-slot:actions>
                @if (! empty($showBulkAssign ?? false))
                    <a
                        href="{{ route('profile.notification-channels.bulk-assign') }}"
                        wire:navigate
                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-paper-airplane class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Bulk assign') }}
                    </a>
                @endif
                @if ($showShellAdd)
                    <button
                        type="button"
                        wire:click="openCreateChannelModal"
                        class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Add channel') }}
                    </button>
                @endif
            </x-slot:actions>

            @include($contentPartial)
        </x-profile-shell>
    @endif

    {{-- The page root is a plain <div>, not a component, so a named "modals"
         slot here is orphaned on every Livewire re-render — which left the
         delete confirmation never appearing. The partial teleports to <body>,
         so include it directly (same fix as sites/show.blade.php). --}}
    @include('livewire.partials.confirm-action-modal')
</div>
