@php
    $maintenanceWeekdays = config('server_settings.maintenance_weekdays', []);
    $serverEventLabels = config('notification_events.categories.server.events', []);
    $hasLegacyNotes = is_string($server->meta['notif_routing_note'] ?? null) && trim((string) $server->meta['notif_routing_note']) !== '';
    $subscriptionsByChannel = collect($serverNotifSubscriptions)->groupBy('notification_channel_id');
@endphp

<section id="settings-group-ops" class="space-y-6" aria-labelledby="settings-group-ops-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-ops-title',
        'kicker' => __('Operations'),
        'title' => __('Alerts & planned downtime'),
        'description' => __('Maintenance windows now gate disruptive actions (firewall apply, supervisor restart-all) with a confirm prompt outside the window. Notification routing pins channels to this server\'s server-scoped events.'),
    ])

    <div id="settings-maintenance" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Maintenance window') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('When you prefer disruptive work (upgrades, reboots). Times use your Dply timezone preference in the “Timezone & notes” section below, not the server OS clock.') }}
        </p>
        <form wire:submit="saveMaintenanceWindow" class="mt-6 space-y-5">
            <fieldset @disabled(! $this->canEditServerSettings)>
                <legend class="text-sm font-medium text-brand-ink">{{ __('Preferred days') }}</legend>
                <div class="mt-2 flex flex-wrap gap-3">
                    @foreach ($maintenanceWeekdays as $key => $label)
                        <label class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-sm">
                            <input type="checkbox" wire:model="settingsMaintenanceDays" value="{{ $key }}" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('settingsMaintenanceDays')" class="mt-2" />
            </fieldset>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="settings-maint-start" value="{{ __('Start (local)') }}" />
                    <input
                        id="settings-maint-start"
                        type="time"
                        wire:model="settingsMaintenanceStart"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        @disabled(! $this->canEditServerSettings)
                    />
                    <x-input-error :messages="$errors->get('settingsMaintenanceStart')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="settings-maint-end" value="{{ __('End (local)') }}" />
                    <input
                        id="settings-maint-end"
                        type="time"
                        wire:model="settingsMaintenanceEnd"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        @disabled(! $this->canEditServerSettings)
                    />
                    <x-input-error :messages="$errors->get('settingsMaintenanceEnd')" class="mt-2" />
                </div>
            </div>
            <div>
                <x-input-label for="settings-maint-note" value="{{ __('Note') }}" />
                <textarea
                    id="settings-maint-note"
                    wire:model="settingsMaintenanceNote"
                    rows="3"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                ></textarea>
                <x-input-error :messages="$errors->get('settingsMaintenanceNote')" class="mt-2" />
            </div>
            @if ($this->canEditServerSettings)
                <div class="flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save maintenance window') }}</x-primary-button>
                </div>
            @endif
        </form>
    </div>

    <div id="settings-notifications" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Notification routing') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Pick which org notification channels (Slack, PagerDuty, email, webhook, …) should receive notifications for this server\'s server-scoped events. Each row binds one channel to one event; remove a row to unsubscribe.') }}
        </p>

        <div class="mt-6">
            @if ($subscriptionsByChannel->isEmpty())
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 p-4 text-sm text-brand-moss">
                    {{ __('No notification subscriptions yet for this server. Add one below.') }}
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                    @foreach ($subscriptionsByChannel as $channelId => $subs)
                        @php $channel = $subs->first()->channel; @endphp
                        <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                                <p class="text-xs text-brand-moss">
                                    {{ ucfirst((string) ($channel?->type ?? '—')) }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @foreach ($subs as $sub)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2 py-1 text-[11px] font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10">
                                        {{ $serverEventLabels[$sub->event_key] ?? $sub->event_key }}
                                        @if ($this->canEditServerSettings)
                                            <button
                                                type="button"
                                                wire:click="removeServerNotificationSubscription(@js($sub->id))"
                                                wire:confirm="{{ __('Remove this subscription?') }}"
                                                class="text-brand-moss hover:text-red-700"
                                                aria-label="{{ __('Remove subscription') }}"
                                            >×</button>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($this->canEditServerSettings)
            <form wire:submit="addServerNotificationSubscription" class="mt-6 space-y-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                <p class="text-sm font-medium text-brand-ink">{{ __('Add subscription') }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="notif-add-channel" value="{{ __('Channel') }}" />
                        <select
                            id="notif-add-channel"
                            wire:model="notifAddChannelId"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm"
                        >
                            <option value="">{{ __('Select a channel…') }}</option>
                            @foreach ($assignableChannels as $channel)
                                <option value="{{ $channel->id }}">{{ $channel->label }} ({{ ucfirst($channel->type) }})</option>
                            @endforeach
                        </select>
                        @if ($assignableChannels->isEmpty())
                            <p class="mt-2 text-xs text-brand-moss">
                                {{ __('No assignable channels found. Create one in Settings → Notifications first.') }}
                            </p>
                        @endif
                        <x-input-error :messages="$errors->get('notifAddChannelId')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label value="{{ __('Events') }}" />
                        <div class="mt-1 space-y-1.5">
                            @foreach ($serverEventLabels as $key => $label)
                                <label class="flex items-center gap-2 text-sm text-brand-ink">
                                    <input type="checkbox" wire:model="notifAddEventKeys" value="{{ $key }}" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('notifAddEventKeys')" class="mt-2" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled" :disabled="$assignableChannels->isEmpty()">{{ __('Add subscription') }}</x-primary-button>
                </div>
            </form>
        @endif

        @if ($hasLegacyNotes)
            <div class="mt-6 rounded-xl border border-amber-300/70 bg-amber-50 p-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-amber-900">{{ __('Legacy routing notes') }}</p>
                        <p class="mt-1 text-xs text-amber-800">
                            {{ __('Free-form notes from before structured per-server routing existed. Migrate any actionable info into the channel list above, then dismiss.') }}
                        </p>
                        <pre class="mt-3 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-white px-3 py-2 font-mono text-xs leading-relaxed text-amber-900">{{ $server->meta['notif_routing_note'] }}</pre>
                    </div>
                    @if ($this->canEditServerSettings)
                        <button
                            type="button"
                            wire:click="dismissLegacyRoutingNotes"
                            wire:confirm="{{ __('Dismiss legacy routing notes? This deletes the saved text.') }}"
                            class="shrink-0 rounded-lg border border-amber-400 bg-white px-3 py-1.5 text-xs font-medium text-amber-900 hover:bg-amber-100"
                        >
                            {{ __('Dismiss') }}
                        </button>
                    @endif
                </div>
            </div>
        @endif
    </div>
</section>
