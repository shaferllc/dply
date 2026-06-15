<x-server-workspace-layout
    :server="$server"
    active="notifications"
    :title="__('Notifications')"
    :description="__('Route notification channels to this server\'s events — one place for every alert the box can raise. The same subscriptions are also editable from each feature\'s own Notifications tab.')"
>

    <div class="space-y-6">
        <x-server-workspace-tablist :aria-label="__('Notifications sections')" scroll class="sm:min-w-0 sm:flex-1">
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

        @if ($notifTab === 'subscriptions')
        <section class="dply-card overflow-hidden">
            <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
                <div class="flex min-w-0 items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Alerts') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Server event subscriptions') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss max-w-2xl">
                            {{ __('Subscribe notification channels to server events. Dply delivers in-app notifications and routes to the channels you select here.') }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('profile.notification-channels') }}" wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        <x-heroicon-o-bell class="h-4 w-4 shrink-0" />
                        {{ __('My channels') }}
                    </a>
                    @if ($server->organization_id)
                        <a href="{{ route('organizations.notification-channels', $server->organization_id) }}" wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-building-office-2 class="h-4 w-4 shrink-0" />
                            {{ __('Organization channels') }}
                        </a>
                        <a href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}" wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-adjustments-horizontal class="h-4 w-4 shrink-0" />
                            {{ __('Advanced assignment') }}
                        </a>
                    @endif
                </div>
            </div>

            <div class="p-6 sm:p-8">
                @include('livewire.partials.notification-channel-matrix', [
                    'channels' => $assignableNotificationChannels,
                    'eventGroups' => $eventCategories,
                    'selections' => $channelEventSelections,
                    'model' => 'channelEventSelections',
                    'showFilter' => true,
                ])
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
                <p class="text-xs text-brand-mist">{{ __('Each channel routes its own events. Removing = untick; channels not shown here are never changed.') }}</p>
                <x-primary-button type="button" wire:click="saveServerNotificationSubscriptions" wire:loading.attr="disabled" wire:target="saveServerNotificationSubscriptions">
                    <span wire:loading.remove wire:target="saveServerNotificationSubscriptions">{{ __('Save subscriptions') }}</span>
                    <span wire:loading wire:target="saveServerNotificationSubscriptions">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </section>
        @endif

        {{-- Integration (outbound) webhooks — org-scoped, managed in Organization → Automation --}}
        @if ($notifTab === 'webhooks')
        <section class="dply-card overflow-hidden">
            <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
                <div class="flex min-w-0 items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-arrow-up-right class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Outbound') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Integration webhooks') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss max-w-2xl">
                            {{ __('Organization-wide outbound webhook destinations (Slack / Discord / Teams). These apply to every server in the organization and are configured under Organization → Automation. Server events route a limited set today (e.g. Insights alerts).') }}
                        </p>
                    </div>
                </div>
                @if ($server->organization_id)
                    <a href="{{ route('organizations.automation', $server->organization_id) }}" wire:navigate
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        <x-heroicon-o-cog-6-tooth class="h-4 w-4 shrink-0" />
                        {{ __('Manage in Automation') }}
                    </a>
                @endif
            </div>

            <div class="p-6 sm:p-8">
                @if ($organizationWebhookDestinations->isEmpty())
                    <p class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-3 text-sm text-brand-moss">
                        {{ __('No organization-wide webhook destinations yet.') }}
                    </p>
                @else
                    <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10">
                        @foreach ($organizationWebhookDestinations as $hook)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm" wire:key="srv-orghook-{{ $hook->id }}">
                                <div>
                                    <span class="font-medium text-brand-ink">{{ $hook->name }}</span>
                                    <span class="ml-2 text-brand-moss">{{ ucfirst($hook->driver) }}</span>
                                </div>
                                <span class="text-xs {{ $hook->enabled ? 'text-green-700' : 'text-brand-mist' }}">{{ $hook->enabled ? __('on') : __('off') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
        @endif
    </div>

    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
