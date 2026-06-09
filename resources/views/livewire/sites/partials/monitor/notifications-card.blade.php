@php
    /** @var \Illuminate\Support\Collection $uptimeNotifSubscriptions */
    /** @var \Illuminate\Support\Collection $uptimeNotifChannels */
    /** @var array<string, string> $uptimeNotifEventLabels */
    $card = 'dply-card overflow-hidden';
    $subscriptionsByChannel = $uptimeNotifSubscriptions->groupBy('notification_channel_id');
@endphp

<div class="{{ $card }}">
    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Alerts') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Uptime alerts') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('Route a channel (email, Slack, Discord, webhook…) to this site\'s monitors — get paged when a check goes down, recovers, slows to degraded, or a TLS certificate is about to expire.') }}
                </p>
            </div>
        </div>
        <x-secondary-button size="sm" href="{{ route('profile.notification-channels.bulk-assign', ['site' => $site->id]) }}" wire:navigate class="shrink-0 whitespace-nowrap">
            {{ __('Manage in Settings') }}
            <x-heroicon-o-arrow-right class="h-4 w-4 shrink-0" aria-hidden="true" />
        </x-secondary-button>
    </div>

    <div class="mx-6 mt-5 flex items-start gap-2.5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-sm leading-relaxed text-brand-moss sm:mx-7">
        <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
        <p>{{ __('Members of this organization always see uptime alerts in their in-app inbox — these channels add external delivery. "Down & recovered" pairs both edges; "Degraded" fires on slow responses; "SSL certificate expiring" warns before a cert lapses. Alerts fire once per transition, never on every repeated probe.') }}</p>
    </div>

    {{-- Current subscriptions --}}
    <div class="px-6 py-5 sm:px-7">
        @if ($subscriptionsByChannel->isEmpty())
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 p-6 text-center">
                <x-heroicon-o-bell-slash class="mx-auto h-8 w-8 text-brand-mist" aria-hidden="true" />
                <p class="mt-3 text-sm text-brand-moss">
                    {{ __('No external channels are routed for uptime alerts yet.') }}
                </p>
                <p class="mt-1 text-xs text-brand-mist">
                    {{ __('Add one below to get an email or chat message the moment a monitor changes state.') }}
                </p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                @foreach ($subscriptionsByChannel as $channelId => $subs)
                    @php $channel = $subs->first()->channel; @endphp
                    <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="uptime-notif-ch-{{ $channelId }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                            <p class="text-xs text-brand-moss">{{ ucfirst((string) ($channel?->type ?? '—')) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach ($subs as $sub)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2 py-1 text-[11px] font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10" wire:key="uptime-notif-sub-{{ $sub->id }}">
                                    {{ $uptimeNotifEventLabels[$sub->event_key] ?? $sub->event_key }}
                                    <button
                                        type="button"
                                        wire:click="removeUptimeNotificationSubscription(@js($sub->id))"
                                        wire:confirm="{{ __('Stop routing this event to :channel?', ['channel' => $channel?->label ?? __('this channel')]) }}"
                                        class="text-brand-moss hover:text-red-700"
                                        aria-label="{{ __('Remove subscription') }}"
                                    >&times;</button>
                                </span>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Add subscription --}}
    <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-7">
        <p class="text-sm font-medium text-brand-ink">{{ __('Add a channel') }}</p>
        <form wire:submit="addUptimeNotificationSubscription" class="mt-4 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="uptime-notif-channel" value="{{ __('Channel') }}" />
                    <select
                        id="uptime-notif-channel"
                        wire:model="uptime_notif_channel_id"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    >
                        <option value="">{{ __('Select a channel…') }}</option>
                        @foreach ($uptimeNotifChannels as $channel)
                            <option value="{{ $channel->id }}">{{ $channel->label }} ({{ ucfirst($channel->type) }})</option>
                        @endforeach
                    </select>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @if ($uptimeNotifChannels->isEmpty())
                            <p class="text-xs text-brand-moss">{{ __('You have no notification channels yet.') }}</p>
                        @endif
                        <button
                            type="button"
                            wire:click="openCreateChannelModal"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-ink hover:text-brand-sage"
                        >
                            <x-heroicon-o-plus-circle class="h-4 w-4" aria-hidden="true" />
                            {{ __('Create a channel') }}
                        </button>
                        <span class="text-[10px] text-brand-mist">·</span>
                        <a href="{{ route('profile.notification-channels') }}" class="text-xs text-brand-mist hover:text-brand-ink" wire:navigate>
                            {{ __('Manage all in Settings →') }}
                        </a>
                    </div>
                    <x-input-error :messages="$errors->get('uptime_notif_channel_id')" class="mt-2" />
                </div>
                <div>
                    <x-input-label value="{{ __('Events') }}" />
                    <div class="mt-1 space-y-1.5">
                        @foreach ($uptimeNotifEventLabels as $key => $label)
                            <label class="flex items-center gap-2 text-sm text-brand-ink">
                                <input
                                    type="checkbox"
                                    wire:model="uptime_notif_event_keys"
                                    value="{{ $key }}"
                                    class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage"
                                />
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('uptime_notif_event_keys')" class="mt-2" />
                </div>
            </div>
            <div class="flex justify-end">
                <x-primary-button type="submit" wire:loading.attr="disabled" :disabled="$uptimeNotifChannels->isEmpty()">
                    {{ __('Add subscription') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</div>
