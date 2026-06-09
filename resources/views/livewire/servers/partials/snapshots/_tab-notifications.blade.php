@php
    /** @var \Illuminate\Support\Collection $notifSubscriptions */
    /** @var \Illuminate\Support\Collection $notifChannels */
    /** @var array<string, string> $notifEventLabels */
    $card = 'dply-card overflow-hidden';
    $subscriptionsByChannel = $notifSubscriptions->groupBy('notification_channel_id');
@endphp

<div class="{{ $card }}">
    <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                <x-heroicon-o-bell class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Snapshot alerts') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s snapshot events — images, database, and cache. Each row binds one channel to one event.') }}
                </p>
            </div>
        </div>
        <x-secondary-button size="sm" href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}" wire:navigate class="shrink-0 whitespace-nowrap">
            {{ __('Manage in Settings') }}
            <x-heroicon-o-arrow-right class="h-4 w-4 shrink-0" aria-hidden="true" />
        </x-secondary-button>
    </div>

    <div class="mx-6 mt-5 flex items-start gap-2.5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-sm leading-relaxed text-brand-moss sm:mx-8">
        <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
        <p>{{ __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever a snapshot is created, restored, or deleted — no setup needed. Add a channel below only to also send email / chat / webhook alerts.') }}</p>
    </div>

    {{-- Current subscriptions --}}
    <div class="px-6 py-5 sm:px-8">
        @if ($subscriptionsByChannel->isEmpty())
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 p-6 text-center">
                <x-heroicon-o-bell-slash class="mx-auto h-8 w-8 text-brand-mist" aria-hidden="true" />
                <p class="mt-3 text-sm text-brand-moss">
                    {{ __('No external channels are routed for snapshot events yet.') }}
                </p>
                <p class="mt-1 text-xs text-brand-mist">
                    {{ __('Add one below to get an email or chat message when a snapshot is created, restored, or deleted.') }}
                </p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                @foreach ($subscriptionsByChannel as $channelId => $subs)
                    @php $channel = $subs->first()->channel; @endphp
                    <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="snap-notif-ch-{{ $channelId }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                            <p class="text-xs text-brand-moss">{{ ucfirst((string) ($channel?->type ?? '—')) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach ($subs as $sub)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2 py-1 text-[11px] font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10" wire:key="snap-notif-sub-{{ $sub->id }}">
                                    {{ $notifEventLabels[$sub->event_key] ?? $sub->event_key }}
                                    <button
                                        type="button"
                                        wire:click="removeSnapshotNotificationSubscription(@js($sub->id))"
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
    <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-8">
        <p class="text-sm font-medium text-brand-ink">{{ __('Add a channel') }}</p>
        <form wire:submit="addSnapshotNotificationSubscription" class="mt-4 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="snap-notif-channel" value="{{ __('Channel') }}" />
                    <select
                        id="snap-notif-channel"
                        wire:model="notif_channel_id"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    >
                        <option value="">{{ __('Select a channel…') }}</option>
                        @foreach ($notifChannels as $channel)
                            <option value="{{ $channel->id }}">{{ $channel->label }} ({{ ucfirst($channel->type) }})</option>
                        @endforeach
                    </select>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @if ($notifChannels->isEmpty())
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
                    <x-input-error :messages="$errors->get('notif_channel_id')" class="mt-2" />
                </div>
                <div>
                    <x-input-label value="{{ __('Events') }}" />
                    <div class="mt-1 space-y-1.5">
                        @foreach ($notifEventLabels as $key => $label)
                            <label class="flex items-center gap-2 text-sm text-brand-ink">
                                <input
                                    type="checkbox"
                                    wire:model="notif_event_keys"
                                    value="{{ $key }}"
                                    class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage"
                                />
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('notif_event_keys')" class="mt-2" />
                </div>
            </div>
            <div class="flex justify-end">
                <x-primary-button type="submit" wire:loading.attr="disabled" :disabled="$notifChannels->isEmpty()">
                    {{ __('Add subscription') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</div>
