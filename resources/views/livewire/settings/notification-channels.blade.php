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
                :organization="$organization"
                :section="$orgShellSection ?? 'notifications'"
                :title="$pageTitle"
                :description="$intro"
                icon="heroicon-o-bell"
                :breadcrumb="$breadcrumbs ?? null"
            >
                <x-slot:actions>
                    @if (! empty($showBulkAssign ?? false))
                        <x-outline-link href="{{ route('profile.notification-channels.bulk-assign') }}" wire:navigate>
                            <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Bulk assign') }}
                        </x-outline-link>
                    @endif
                    @if ($showShellAdd)
                        <button
                            type="button"
                            wire:click="openCreateChannelModal"
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Add channel') }}
                        </button>
                    @endif
                </x-slot:actions>

                @include('livewire.settings.partials.notification-channels-content')
            </x-organization-shell>
        </div>
    @else
        <x-profile-shell
            :title="$pageTitle"
            :description="$intro"
            icon="heroicon-o-bell"
        >
            <x-slot:actions>
                <x-outline-link href="{{ route('settings.profile') }}" wire:navigate>
                    <x-heroicon-o-user-circle class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Back to profile') }}
                </x-outline-link>
                @if (! empty($showBulkAssign ?? false))
                    <x-outline-link href="{{ route('profile.notification-channels.bulk-assign') }}" wire:navigate>
                        <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Bulk assign') }}
                    </x-outline-link>
                @endif
                @if ($showShellAdd)
                    <button
                        type="button"
                        wire:click="openCreateChannelModal"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add channel') }}
                    </button>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-2">
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-4 py-3">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Personal') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $channelCount }}</span>
                            <span class="text-xs text-brand-moss">{{ trans_choice('channel|channels', $channelCount) }}</span>
                        </dd>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('You own') }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-4 py-3">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Organization') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ isset($organizationChannels) ? $organizationChannels->count() : 0 }}</span>
                            <span class="text-xs text-brand-moss">{{ trans_choice('available|available', isset($organizationChannels) ? $organizationChannels->count() : 0) }}</span>
                        </dd>
                        <p class="mt-1 text-xs text-brand-mist">{{ ($currentOrganization ?? null) ? $currentOrganization->name : __('No current org') }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-4 py-3">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Teams') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ ($teamChannelGroups ?? collect())->sum(fn ($e) => $e['channels']->count()) }}</span>
                            <span class="text-xs text-brand-moss">{{ trans_choice('available|available', ($teamChannelGroups ?? collect())->sum(fn ($e) => $e['channels']->count())) }}</span>
                        </dd>
                        <p class="mt-1 text-xs text-brand-mist">{{ trans_choice(':n team|:n teams', ($teamChannelGroups ?? collect())->count(), ['n' => ($teamChannelGroups ?? collect())->count()]) }}</p>
                    </div>
                </dl>
            </x-slot:stats>

            @include('livewire.settings.partials.notification-channels-content')
        </x-profile-shell>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
