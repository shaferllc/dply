<div class="space-y-6">
    <section class="dply-card overflow-hidden">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
                </span>
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
                    <x-heroicon-o-bell class="h-3.5 w-3.5 shrink-0" />
                    {{ __('My channels') }}
                </a>
                @if ($site->organization_id)
                    <a
                        href="{{ route('organizations.notification-channels', $site->organization_id) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-building-office-2 class="h-3.5 w-3.5 shrink-0" />
                        {{ __('Organization channels') }}
                    </a>
                    <a
                        href="{{ route('profile.notification-channels.bulk-assign', ['site' => $site->id]) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-adjustments-horizontal class="h-3.5 w-3.5 shrink-0" />
                        {{ __('Advanced assignment') }}
                    </a>
                @endif
            </div>
        </div>

        <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-2">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Channels') }}</p>
                <div class="mt-3 space-y-2">
                    @forelse ($assignableNotificationChannels as $channel)
                        <label class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-sm text-brand-ink">
                            <input
                                type="checkbox"
                                wire:model.live="site_notification_channel_ids"
                                value="{{ $channel->id }}"
                                class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                            >
                            <span>
                                <span class="font-medium">{{ $channel->label }}</span>
                                <span class="text-brand-mist">[{{ \App\Models\NotificationChannel::labelForType($channel->type) }}]</span>
                            </span>
                        </label>
                    @empty
                        <p class="rounded-xl border border-dashed border-brand-ink/15 px-3 py-3 text-sm text-brand-moss">
                            {{ __('No channels available yet. Create one under My channels or Organization channels.') }}
                        </p>
                    @endforelse
                </div>
                @error('site_notification_channel_ids')
                    <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Site events') }}</p>
                <div class="mt-3 space-y-2">
                    @foreach ($siteNotificationEventLabels as $eventKey => $eventLabel)
                        <label class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-sm text-brand-ink">
                            <input
                                type="checkbox"
                                wire:model.live="site_notification_event_keys"
                                value="{{ $eventKey }}"
                                class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                            >
                            <span>{{ $eventLabel }}</span>
                        </label>
                    @endforeach
                </div>
                @error('site_notification_event_keys')
                    <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
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

    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-arrow-up-right class="h-5 w-5" aria-hidden="true" />
            </span>
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
            </div>
            <x-primary-button type="submit" class="!text-sm w-fit">{{ __('Add webhook destination') }}</x-primary-button>
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

    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-arrow-down-on-square class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Inbound') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deploy webhook') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('The deploy URL, secret rotation, and Quick deploy live under Repository. IP restrictions stay below.') }}</p>
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'repository']) }}" wire:navigate class="mt-2 inline-flex text-sm font-semibold text-brand-forest hover:text-brand-sage hover:underline">{{ __('Open Repository → Inbound deploy webhook') }} →</a>
            </div>
        </div>

        <form wire:submit="saveWebhookSecurity" class="space-y-3 p-6 sm:p-8">
            <x-input-label for="webhook_allowed_ips_text" value="{{ __('Optional IP allow list (one IPv4/IPv6 or IPv4 CIDR per line)') }}" />
            <textarea id="webhook_allowed_ips_text" wire:model="webhook_allowed_ips_text" rows="4" class="w-full rounded-md border-brand-ink/15 shadow-sm font-mono text-xs" placeholder="203.0.113.10&#10;192.0.2.0/24"></textarea>
            <x-input-error :messages="$errors->get('webhook_allowed_ips_text')" class="mt-1" />
            <x-primary-button type="submit">{{ __('Save allow list') }}</x-primary-button>
        </form>
    </section>

    <x-cli-snippet tone="stub" />
</div>
