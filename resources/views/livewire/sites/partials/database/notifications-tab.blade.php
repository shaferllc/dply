@php
    /** @var \Illuminate\Support\Collection $notifSubscriptions */
    /** @var \Illuminate\Support\Collection $notifChannels */
    /** @var array<string, string> $notifEventLabels */
    $subscriptionsByChannel = ($notifSubscriptions ?? collect())->groupBy('notification_channel_id');
    $notifChannels = $notifChannels ?? collect();
    $notifEventLabels = $notifEventLabels ?? [];
@endphp

<div class="min-w-0">
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3 sm:px-6">
        <div class="min-w-0 flex-1 basis-72">
            <div class="flex items-center gap-2">
                <x-heroicon-o-bell-alert class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Database notifications') }}</h3>
            </div>
            <p class="mt-1 max-w-3xl text-xs leading-relaxed text-brand-moss">
                {{ __('Route a channel (email, Slack, Discord, webhook…) to this server\'s database events — created/removed, users, engines, and shared credentials. Each row binds one channel to one event.') }}
            </p>
            {{-- Folded up from its own full-width callout strip: it\'s a caveat on
                 the sentence above, not a separate section. --}}
            <p class="mt-1 max-w-3xl text-xs leading-relaxed text-brand-mist">
                {{ __('Owners and org admins already get an in-app notification (the bell) and inbox entry for these events — no setup needed. Add a channel below only to also send email / chat / webhook alerts.') }}
            </p>
        </div>
        @if ($site->organization_id)
            <x-secondary-button size="xs" href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}" wire:navigate class="shrink-0 whitespace-nowrap">
                {{ __('Advanced assignment') }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </x-secondary-button>
        @endif
    </div>

    {{-- Current subscriptions --}}
    <div class="px-5 py-4 sm:px-6">
        @if ($subscriptionsByChannel->isEmpty())
            <div class="rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-5 text-center">
                <x-heroicon-o-bell-slash class="mx-auto h-6 w-6 text-brand-mist" aria-hidden="true" />
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('No external channels are routed for database events yet.') }}
                </p>
                <p class="mt-1 text-xs text-brand-mist">
                    {{ __('Add one below to get an email or chat message when a database, user, engine, or credential changes.') }}
                </p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                @foreach ($subscriptionsByChannel as $channelId => $subs)
                    @php $channel = $subs->first()->channel; @endphp
                    <li class="flex flex-col gap-2 px-3 py-2 sm:flex-row sm:items-center sm:justify-between" wire:key="db-notif-ch-{{ $channelId }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                            <p class="text-xs text-brand-moss">{{ ucfirst((string) ($channel?->type ?? '—')) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach ($subs as $sub)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2 py-1 text-xs font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10" wire:key="db-notif-sub-{{ $sub->id }}">
                                    {{ $notifEventLabels[$sub->event_key] ?? $sub->event_key }}
                                    <button
                                        type="button"
                                        wire:click="removeDatabaseNotificationSubscription(@js($sub->id))"
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
    <div class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Add a channel') }}</p>
        <form wire:submit="addDatabaseNotificationSubscription" class="mt-2 space-y-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="db-notif-channel" value="{{ __('Channel') }}" />
                    <select
                        id="db-notif-channel"
                        wire:model="notif_channel_id"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
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
                        <span class="text-2xs text-brand-mist">·</span>
                        <a href="{{ route('profile.notification-channels') }}" class="text-xs text-brand-mist hover:text-brand-ink" wire:navigate>
                            {{ __('Manage all in Settings →') }}
                        </a>
                    </div>
                    <x-input-error :messages="$errors->get('notif_channel_id')" class="mt-2" />
                </div>
                <div>
                    <x-input-label value="{{ __('Events') }}" />
                    <div class="mt-1 grid gap-x-4 gap-y-0.5 sm:grid-cols-2">
                        @foreach ($notifEventLabels as $key => $label)
                            <label class="flex items-center gap-2 text-xs text-brand-ink">
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
                <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" :disabled="$notifChannels->isEmpty()">
                    {{ __('Add subscription') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</div>
