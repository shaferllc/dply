@php
    $notificationsDescription = __('Route notification channels to this server\'s events — one place for every alert the box can raise. The same subscriptions are also editable from each feature\'s own Notifications tab.');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="notifications"
    :title="__('Notifications')"
    :description="$notificationsDescription"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-bell"
            :title="__('Notifications')"
            :note="$notificationsDescription"
            class="border-b border-brand-ink/10"
        />

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Notifications sections')" scroll bare class="!mb-0 w-full">
                <x-server-workspace-tab
                    id="notif-tab-subscriptions"
                    icon="heroicon-o-bell"
                    :active="$notifTab === 'subscriptions'"
                    wire:click="setNotificationsTab('subscriptions')"
                >
                    {{ __('Subscriptions') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="notif-tab-webhooks"
                    icon="heroicon-o-arrow-up-right"
                    :active="$notifTab === 'webhooks'"
                    wire:click="setNotificationsTab('webhooks')"
                >
                    {{ __('Integration webhooks') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        {{-- Skeleton swap, not a dim-and-lock — matches the Logs / Deployments
             tab strips. --}}
        <div class="hidden" wire:loading.class.remove="hidden" wire:target="setNotificationsTab">
            @include('livewire.sites.partials._panel-skeleton')
        </div>

        <div class="relative" wire:loading.class="hidden" wire:target="setNotificationsTab">
            @if ($notifTab === 'subscriptions')
                <x-server-workspace-tab-panel
                    id="notif-panel-subscriptions"
                    labelled-by="notif-tab-subscriptions"
                    panel-class="min-w-0"
                >
                    @php
                        $routedCount = collect($channelEventSelections)->sum(fn ($keys) => count((array) $keys));
                    @endphp
                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-bell-alert"
                        :title="__('Server event subscriptions')"
                        :note="__('Subscribe notification channels to server events. Dply delivers in-app notifications and routes to the channels you select here.')"
                        :count="trans_choice('{0} none routed|{1} :count route|[2,*] :count routes', $routedCount, ['count' => $routedCount])"
                        class="border-b border-brand-ink/10"
                    >
                        <x-slot:actions>
                            <a href="{{ route('profile.notification-channels') }}" wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                <x-heroicon-o-bell class="h-3.5 w-3.5 shrink-0" />
                                {{ __('My channels') }}
                            </a>
                            @if ($server->organization_id)
                                <a href="{{ route('organizations.notification-channels', $server->organization_id) }}" wire:navigate
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-building-office-2 class="h-3.5 w-3.5 shrink-0" />
                                    {{ __('Organization channels') }}
                                </a>
                                <a href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}" wire:navigate
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-adjustments-horizontal class="h-3.5 w-3.5 shrink-0" />
                                    {{ __('Advanced assignment') }}
                                </a>
                            @endif
                        </x-slot:actions>
                    </x-workspace-panel-head>

                    <div class="flex items-start gap-2.5 border-b border-brand-ink/10 bg-brand-sand/15 px-4 py-2.5 text-xs leading-relaxed text-brand-moss sm:px-5">
                        <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        <p>{{ __('Owners and org admins already get an in-app notification (the bell) and inbox entry for server events — no setup needed. Add a channel below only to also send email / chat / webhook alerts.') }}</p>
                    </div>

                    @if ($routedCount === 0)
                        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-brand-ink/10 px-4 py-2.5 text-xs text-brand-moss sm:px-5">
                            <x-heroicon-m-bell-slash class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                            {{ __('No external channels are routed for this server yet — tick events on a channel below to get an email or chat message when something fires.') }}
                        </p>
                    @endif

                    <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                        @include('livewire.partials.notification-channel-matrix', [
                            'channels' => $assignableNotificationChannels,
                            'eventGroups' => $eventCategories,
                            'selections' => $channelEventSelections,
                            'model' => 'channelEventSelections',
                            'showFilter' => true,
                        ])
                    </div>

                    <div class="flex flex-col gap-2 border-b border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <p class="text-xs text-brand-mist">{{ __('Each channel routes its own events. Removing = untick; channels not shown here are never changed.') }}</p>
                        <x-primary-button type="button" wire:click="saveServerNotificationSubscriptions" wire:loading.attr="disabled" wire:target="saveServerNotificationSubscriptions">
                            <span wire:loading.remove wire:target="saveServerNotificationSubscriptions">{{ __('Save subscriptions') }}</span>
                            <span wire:loading wire:target="saveServerNotificationSubscriptions">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </x-server-workspace-tab-panel>
            @endif

            @if ($notifTab === 'webhooks')
                <x-server-workspace-tab-panel
                    id="notif-panel-webhooks"
                    labelled-by="notif-tab-webhooks"
                    panel-class="min-w-0"
                >
                    @include('livewire.servers.partials.notifications._outbound-webhook')

                </x-server-workspace-tab-panel>
            @endif
        </div>
    </section>

    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
