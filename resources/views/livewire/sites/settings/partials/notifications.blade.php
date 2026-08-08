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

    @if ($notifTab === 'webhooks')
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                icon="heroicon-o-arrow-up-right"
                :title="__('Integration webhooks')"
                :note="__('Dply POSTs to these URLs when matching events occur. Payloads are adapter-specific: Slack uses text, Discord uses content, Teams uses a MessageCard body.')"
                :count="$siteIntegrationWebhookDestinations->count() ?: null"
                class="border-b border-brand-ink/10"
            />

            <div class="space-y-3 px-5 py-4 sm:px-6">
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

    </div>

    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
        <x-cli-snippet tone="stub" />
    </div>

    @include('livewire.partials.create-notification-channel-modal')
</div>
