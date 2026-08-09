@php
    /** @var \Illuminate\Support\Collection $notifSubscriptions */
    /** @var \Illuminate\Support\Collection $notifChannels */
    /** @var array<string, string> $notifEventLabels */
    $subscriptionsByChannel = $notifSubscriptions->groupBy('notification_channel_id');
@endphp

{{-- Nested inside the merged Security card — dense heads, same as the other
     sub-tabs. The icon-badge + eyebrow + title + prose stack and the standalone
     explainer paragraph below it cost ~160px before the first channel row. --}}
<div>
    <x-workspace-panel-head
        dense
        icon="heroicon-o-bell"
        :title="__('Security digest alerts')"
        :count="$subscriptionsByChannel->isNotEmpty()
            ? trans_choice('{1} :count channel|[2,*] :count channels', $subscriptionsByChannel->count(), ['count' => $subscriptionsByChannel->count()])
            : null"
        :note="__('Route a channel (email, Slack, Discord, webhook…) to this server’s security digest — critical / warning findings and recoveries. Findings are evaluated when the digest is scanned, manually via Refresh and on the daily sweep; alerts only fire when posture worsens into warning / critical or recovers.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <a
                href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}"
                wire:navigate
                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                {{ __('Manage in Settings') }}
                <x-heroicon-m-arrow-right class="h-3 w-3 shrink-0" aria-hidden="true" />
            </a>
        </x-slot:actions>
    </x-workspace-panel-head>

    @if ($subscriptionsByChannel->isEmpty())
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-brand-ink/10 px-4 py-2.5 text-xs text-brand-moss sm:px-5">
            <x-heroicon-m-bell-slash class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
            {{ __('No external channels are routed for security digest events yet — add one below to get an email or chat message when this server’s posture degrades.') }}
        </p>
    @else
        <ul class="divide-y divide-brand-ink/10 border-b border-brand-ink/10">
            @foreach ($subscriptionsByChannel as $channelId => $subs)
                @php $channel = $subs->first()->channel; @endphp
                <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2 sm:px-5" wire:key="secdigest-notif-ch-{{ $channelId }}">
                    <p class="min-w-0 truncate text-xs font-semibold text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                    <p class="shrink-0 text-xs text-brand-mist">{{ ucfirst((string) ($channel?->type ?? '—')) }}</p>
                    <div class="ml-auto flex flex-wrap items-center gap-1.5">
                        @foreach ($subs as $sub)
                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 text-2xs font-semibold text-brand-ink ring-1 ring-inset ring-brand-ink/10" wire:key="secdigest-notif-sub-{{ $sub->id }}">
                                {{ $notifEventLabels[$sub->event_key] ?? $sub->event_key }}
                                <button
                                    type="button"
                                    wire:click="removeSecurityDigestNotificationSubscription(@js($sub->id))"
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

    <x-workspace-panel-head
        dense
        icon="heroicon-o-plus-circle"
        :title="__('Add a channel')"
        :note="__('Pick a channel, then the digest events it should receive.')"
        class="border-b border-brand-ink/10"
    />

    <div class="px-4 py-3.5 sm:px-5">
        <form wire:submit="addSecurityDigestNotificationSubscription" class="space-y-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="secdigest-notif-channel" value="{{ __('Channel') }}" />
                    <select
                        id="secdigest-notif-channel"
                        wire:model="notif_channel_id"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    >
                        <option value="">{{ __('Select a channel…') }}</option>
                        @foreach ($notifChannels as $channel)
                            <option value="{{ $channel->id }}">{{ $channel->label }} ({{ ucfirst($channel->type) }})</option>
                        @endforeach
                    </select>
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                        @if ($notifChannels->isEmpty())
                            <p class="text-xs text-brand-moss">{{ __('You have no notification channels yet.') }}</p>
                        @endif
                        <button
                            type="button"
                            wire:click="openCreateChannelModal"
                            class="inline-flex items-center gap-1 text-xs font-semibold text-brand-ink hover:text-brand-sage"
                        >
                            <x-heroicon-m-plus-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Create a channel') }}
                        </button>
                        <span class="text-2xs text-brand-mist" aria-hidden="true">·</span>
                        <a href="{{ route('profile.notification-channels') }}" class="text-xs text-brand-mist hover:text-brand-ink" wire:navigate>
                            {{ __('Manage all in Settings →') }}
                        </a>
                    </div>
                    <x-input-error :messages="$errors->get('notif_channel_id')" class="mt-2" />
                </div>
                <div>
                    <x-input-label value="{{ __('Events') }}" />
                    <div class="mt-1 space-y-1">
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
                <x-primary-button type="submit" wire:loading.attr="disabled" :disabled="$notifChannels->isEmpty()">
                    {{ __('Add subscription') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</div>
