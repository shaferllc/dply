@php
    /** @var \Illuminate\Support\Collection $notifSubscriptions */
    /** @var \Illuminate\Support\Collection $notifChannels */
    /** @var array<string, string> $notifEventLabels */
    $subscriptionsByChannel = $notifSubscriptions->groupBy('notification_channel_id');
@endphp


{{-- Nested inside Errors merged card — compact strips, no second page card. --}}
<div class="min-w-0">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-heroicon-o-bell class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Error alerts') }}
        </h3>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ __('Route a channel (email, Slack, Discord, webhook…) to this site’s error stream.') }}">
            {{ __('Route a channel (email, Slack, Discord, webhook…) to this site’s error stream.') }}
        </p>
        <x-secondary-button size="sm" href="{{ route('profile.notification-channels.bulk-assign', ['site' => $site->id]) }}" wire:navigate class="ml-auto shrink-0 whitespace-nowrap">
            {{ __('Manage in Settings') }}
            <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        </x-secondary-button>
    </div>

    <p class="border-b border-brand-ink/10 px-3 py-2 text-xs leading-snug text-brand-moss sm:px-4">
        {{ __('Alerts fire once per captured failure. “Deployment failed” covers this site’s deploys; “Site operation failed” covers everything else — same subscriptions as the') }}
        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'notifications']) }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ __('Notifications page') }}</a>.
    </p>

    {{-- Current subscriptions --}}
    @if ($subscriptionsByChannel->isEmpty())
        <div class="border-b border-brand-ink/10 px-3 py-4 sm:px-4">
            <x-empty-state
                borderless
                compact
                icon="heroicon-o-bell-slash"
                :title="__('No error channels yet')"
                :description="__('Add one below to get an email or chat message the moment something fails on this site.')"
            />
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10 border-b border-brand-ink/10">
            @foreach ($subscriptionsByChannel as $channelId => $subs)
                @php $channel = $subs->first()->channel; @endphp
                <li class="flex flex-col gap-1.5 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-4" wire:key="errors-notif-ch-{{ $channelId }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                        <p class="text-xs text-brand-moss">{{ ucfirst((string) ($channel?->type ?? '—')) }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach ($subs as $sub)
                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 text-xs font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10" wire:key="errors-notif-sub-{{ $sub->id }}">
                                {{ $notifEventLabels[$sub->event_key] ?? $sub->event_key }}
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('removeErrorsNotificationSubscription', @js([(string) $sub->id]), @js(__('Remove subscription')), @js(__('Stop routing this event to :channel?', ['channel' => $channel?->label ?? __('this channel')])), @js(__('Remove')), true)"
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

    {{-- Add subscription --}}
    <div class="px-3 py-3 sm:px-4">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Add a channel') }}</p>
        <form wire:submit="addErrorsNotificationSubscription" class="mt-2.5 space-y-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="errors-notif-channel" value="{{ __('Channel') }}" />
                    <select
                        id="errors-notif-channel"
                        wire:model="notif_channel_id"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 dark:bg-brand-ink/5"
                    >
                        <option value="">{{ __('Select a channel…') }}</option>
                        @foreach ($notifChannels as $channel)
                            <option value="{{ $channel->id }}">{{ $channel->label }} ({{ ucfirst($channel->type) }})</option>
                        @endforeach
                    </select>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
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
                    <x-input-error :messages="$errors->get('notif_channel_id')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label value="{{ __('Events') }}" />
                    <div class="mt-1 space-y-1">
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
                    <x-input-error :messages="$errors->get('notif_event_keys')" class="mt-1.5" />
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
