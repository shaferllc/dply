{{-- Nested inside Settings Notifications merged card — flush tabs + strips. --}}
<div class="min-w-0">
    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
        <x-server-workspace-tablist
            :aria-label="__('Notifications sections')"
            scroll
            bare class="!mb-0 w-full"
        >
            <x-server-workspace-tab
                id="notif-tab-subscriptions"
                icon="heroicon-o-bell"
                :active="$notifTab === 'subscriptions'"
                wire:click="setNotificationsTab('subscriptions')"
            >
                {{ __('Subscriptions') }}
            </x-server-workspace-tab>
        </x-server-workspace-tablist>
    </div>

    {{-- Same skeleton-swap the Repository / Deployments / Laravel / Monitor tabs
         use: on a tab switch wire:loading paints the shared panel skeleton
         instantly (client-side, no extra request) instead of leaving the
         previous tab's content frozen until the round-trip lands. --}}
    <div class="hidden" wire:loading.class.remove="hidden" wire:target="setNotificationsTab">
        @include('livewire.sites.partials._panel-skeleton')
    </div>

    <div wire:loading.class="hidden" wire:target="setNotificationsTab">
    @if ($notifTab === 'subscriptions')
        <section class="border-b border-brand-ink/10">
            {{-- The "Errors → Notifications" cross-link used to be a sentence of
                 prose below the header; as a head action it keeps the affordance
                 and drops two lines. --}}
            @php
                $notifActionClass = 'inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40';
            @endphp
            <x-workspace-panel-head
                icon="heroicon-o-bell-alert"
                :title="__('Deploy and uptime notifications')"
                :note="__('Subscribe channels to this site\'s events — expand one to pick what it receives. The Errors tab edits the same subscriptions.')"
                class="border-b border-brand-ink/10"
            >
                <x-slot:actions>
                    <a href="{{ route('profile.notification-channels') }}" wire:navigate class="{{ $notifActionClass }}">
                        <x-heroicon-o-bell class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                        {{ __('My channels') }}
                    </a>
                    @if ($site->organization_id)
                        <a href="{{ route('organizations.notification-channels', $site->organization_id) }}" wire:navigate class="{{ $notifActionClass }}">
                            <x-heroicon-o-building-office-2 class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                            {{ __('Organization') }}
                        </a>
                        <a href="{{ route('profile.notification-channels.bulk-assign', ['site' => $site->id]) }}" wire:navigate class="{{ $notifActionClass }}">
                            <x-heroicon-o-adjustments-horizontal class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                            {{ __('Advanced') }}
                        </a>
                    @endif
                    <a href="{{ route('sites.errors', ['server' => $server, 'site' => $site, 'tab' => 'notifications']) }}" wire:navigate class="{{ $notifActionClass }}">
                        <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                        {{ __('Errors') }}
                    </a>
                </x-slot:actions>
            </x-workspace-panel-head>

            <div class="px-5 py-4 sm:px-6">
                @include('livewire.partials.notification-channel-matrix', [
                    'channels' => $assignableNotificationChannels,
                    'eventGroups' => $notificationEventGroups,
                    'selections' => $channelEventSelections,
                    'model' => 'channelEventSelections',
                    'showFilter' => false,
                ])
            </div>

            <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
                <x-primary-button
                    size="sm"
                    type="button"
                    wire:click="saveSiteNotificationSubscriptions"
                    wire:loading.attr="disabled"
                    wire:target="saveSiteNotificationSubscriptions"
                >
                    <span wire:loading.remove wire:target="saveSiteNotificationSubscriptions">{{ __('Save subscriptions') }}</span>
                    <span wire:loading wire:target="saveSiteNotificationSubscriptions">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </section>
    @endif


    </div>

    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
        <x-cli-snippet tone="stub" />
    </div>

    @include('livewire.partials.create-notification-channel-modal')
</div>
