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
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deploy and uptime notifications') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss max-w-2xl">
                        {{ __('Subscribe notification channels to site events. Dply delivers in-app notifications and routes to the channels you select here.') }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a
                    href="{{ route('profile.notification-channels') }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-bell class="h-4 w-4 shrink-0" />
                    {{ __('My channels') }}
                </a>
                @if ($site->organization_id)
                    <a
                        href="{{ route('organizations.notification-channels', $site->organization_id) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-building-office-2 class="h-4 w-4 shrink-0" />
                        {{ __('Organization channels') }}
                    </a>
                    <a
                        href="{{ route('profile.notification-channels.bulk-assign', ['site' => $site->id]) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-adjustments-horizontal class="h-4 w-4 shrink-0" />
                        {{ __('Advanced assignment') }}
                    </a>
                @endif
            </div>
        </div>

        <div class="space-y-3 p-6 sm:p-8">
            <p class="text-xs text-brand-mist">
                {{ __('Each channel routes its own events — expand a channel to choose what it receives. Error-stream events are also editable from the') }}
                <a href="{{ route('sites.errors', ['server' => $server, 'site' => $site, 'tab' => 'notifications']) }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ __('Errors → Notifications tab') }}</a>{{ __('; both edit the same subscriptions.') }}
            </p>
            @include('livewire.partials.notification-channel-matrix', [
                'channels' => $assignableNotificationChannels,
                'eventGroups' => $notificationEventGroups,
                'selections' => $channelEventSelections,
                'model' => 'channelEventSelections',
                'showFilter' => false,
            ])
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <x-primary-button
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

    @if ($notifTab === 'webhooks')
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-arrow-up-right class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Outbound') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Integration webhooks') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss max-w-2xl">
                    {{ __('Dply POSTs to these URLs when matching events occur for this site. Payloads are adapter-specific: Slack uses a text field, Discord uses content, and Microsoft Teams uses a MessageCard-style JSON body.') }}
                </p>
            </div>
        </div>

        <div class="space-y-4 p-6 sm:p-8">
        <form wire:submit="saveSiteIntegrationWebhookDestination" class="flex max-w-2xl flex-col gap-3">
            <div class="flex flex-wrap gap-2">
                <input type="text" wire:model="site_int_hook_name" placeholder="{{ __('Destination name') }}" required class="min-w-[140px] flex-1 rounded-md border-brand-ink/15 text-sm shadow-sm">
                <select wire:model="site_int_hook_driver" class="rounded-md border-brand-ink/15 text-sm shadow-sm">
                    <option value="slack">Slack</option>
                    <option value="discord">Discord</option>
                    <option value="teams">Microsoft Teams</option>
                </select>
            </div>
            <input type="url" wire:model="site_int_hook_url" placeholder="{{ __('Incoming webhook URL') }}" required class="w-full rounded-md border-brand-ink/15 font-mono text-xs shadow-sm">
            <x-input-error :messages="$errors->get('site_int_hook_name')" class="mt-1" />
            <x-input-error :messages="$errors->get('site_int_hook_url')" class="mt-1" />
            <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-brand-ink">
                <span class="w-full text-xs font-medium text-brand-mist">{{ __('Deploy events') }}</span>
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="site_int_evt_success" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"> {{ __('Success') }}</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="site_int_evt_failed" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"> {{ __('Failed') }}</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="site_int_evt_skipped" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"> {{ __('Skipped') }}</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="site_int_evt_deploy_started" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"> {{ __('Deployment started') }}</label>
                <span class="w-full text-xs font-medium text-brand-mist">{{ __('Uptime') }}</span>
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="site_int_evt_uptime_down" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"> {{ __('Monitor down') }}</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="site_int_evt_uptime_recovered" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"> {{ __('Monitor recovered') }}</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="site_int_evt_uptime_degraded" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"> {{ __('Monitor degraded') }}</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="site_int_evt_ssl_expiring" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"> {{ __('SSL certificate expiring') }}</label>
            </div>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveSiteIntegrationWebhookDestination" class="!text-sm w-fit">
                <span wire:loading.remove wire:target="saveSiteIntegrationWebhookDestination">{{ __('Add webhook destination') }}</span>
                <span wire:loading wire:target="saveSiteIntegrationWebhookDestination">{{ __('Adding…') }}</span>
            </x-primary-button>
        </form>

        @if ($siteIntegrationWebhookDestinations->isEmpty())
            <p class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-3 text-sm text-brand-moss">{{ __('No site-scoped webhook destinations yet.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10">
                @foreach ($siteIntegrationWebhookDestinations as $hook)
                    <li class="flex flex-wrap justify-between gap-2 px-4 py-3 text-sm">
                        <div>
                            <span class="font-medium text-brand-ink">{{ $hook->name }}</span>
                            <span class="ml-2 text-brand-moss">{{ $hook->driver }}</span>
                            <span class="ml-2 text-xs {{ $hook->enabled ? 'text-green-700' : 'text-brand-mist' }}">{{ $hook->enabled ? __('on') : __('off') }}</span>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="toggleSiteIntegrationWebhookDestination('{{ $hook->id }}')" class="text-xs text-brand-ink hover:underline">{{ __('Toggle') }}</button>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('deleteSiteIntegrationWebhookDestination', ['{{ $hook->id }}'], @js(__('Remove webhook destination')), @js(__('Remove this webhook destination? Outbound posts to this URL will stop.')), @js(__('Remove')), true)"
                                class="text-xs text-red-600 hover:underline"
                            >
                                {{ __('Remove') }}
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
        </div>
    </section>
    @endif

    <x-cli-snippet tone="stub" />

    @include('livewire.partials.create-notification-channel-modal')
</div>
