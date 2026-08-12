@php
    $subscriptionsByChannel = $serverNotifSubscriptions->groupBy('notification_channel_id');
    $serverEventLabels = $serverEventLabels ?? [];
@endphp

{{-- Nested inside the merged Metrics card — same chrome as Health Notifications. --}}
<div>
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-bell"
        :title="__('Notification routing')"
        :note="__('Bind channels to server events — stale metrics and threshold breaches.')"
    >
        <x-slot:actions>
            <a href="{{ route('servers.notifications', $server) }}" wire:navigate class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                {{ __('Manage') }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
            </a>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="px-3 py-2.5 sm:px-4">
        @if ($subscriptionsByChannel->isEmpty())
            <div class="rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/15 p-4 text-center">
                <x-heroicon-o-bell-slash class="mx-auto h-8 w-8 text-brand-mist" aria-hidden="true" />
                <p class="mt-3 text-sm text-brand-moss">
                    {{ __('No notification subscriptions yet for this server.') }}
                </p>
                <p class="mt-1 text-xs text-brand-mist">
                    {{ __('Add a subscription below to get alerts when metrics go stale or thresholds are breached.') }}
                </p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                @foreach ($subscriptionsByChannel as $channelId => $subs)
                    @php $channel = $subs->first()->channel; @endphp
                    <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="monitor-notif-ch-{{ $channelId }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                            <p class="text-xs text-brand-moss">
                                {{ ucfirst((string) ($channel?->type ?? '—')) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach ($subs as $sub)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2 py-1 text-xs font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10" wire:key="monitor-notif-sub-{{ $sub->id }}">
                                    {{ $serverEventLabels[$sub->event_key] ?? $sub->event_key }}
                                    @if (! $isDeployer)
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

    @if (! $isDeployer)
        <div class="border-t border-brand-ink/10 px-3 py-2.5 sm:px-4">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Add subscription') }}</p>
            <form wire:submit="addServerNotificationSubscription" class="mt-4 space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="notif-add-channel" value="{{ __('Channel') }}" />
                        <select
                            id="notif-add-channel"
                            wire:model="notifAddChannelId"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        >
                            <option value="">{{ __('Select a channel…') }}</option>
                            @foreach ($assignableChannels as $channel)
                                <option value="{{ $channel->id }}">{{ $channel->label }} ({{ ucfirst($channel->type) }})</option>
                            @endforeach
                        </select>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            @if ($assignableChannels->isEmpty())
                                <p class="text-xs text-brand-moss">
                                    {{ __('No assignable channels found.') }}
                                </p>
                            @endif
                            <button
                                type="button"
                                wire:click="openCreateChannelModal"
                                class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-ink hover:text-brand-sage"
                            >
                                <x-heroicon-o-plus-circle class="h-4 w-4" aria-hidden="true" />
                                {{ __('Create new channel') }}
                            </button>
                            <span class="text-2xs text-brand-mist">·</span>
                            <a
                                href="{{ route('profile.notification-channels') }}"
                                class="text-xs text-brand-mist hover:text-brand-ink"
                            >
                                {{ __('Manage all in Settings →') }}
                            </a>
                        </div>
                        <x-input-error :messages="$errors->get('notifAddChannelId')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label value="{{ __('Events') }}" />
                        <div class="mt-1 space-y-1.5">
                            @foreach ($serverEventLabels as $key => $label)
                                <label class="flex items-center gap-2 text-sm text-brand-ink">
                                    <input
                                        type="checkbox"
                                        wire:model="notifAddEventKeys"
                                        value="{{ $key }}"
                                        class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage"
                                    />
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('notifAddEventKeys')" class="mt-2" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <x-primary-button size="xs"
                        type="submit"
                        wire:loading.attr="disabled"
                        :disabled="$assignableChannels->isEmpty()"
                    >
                        {{ __('Add subscription') }}
                    </x-primary-button>
                </div>
            </form>
        </div>
    @endif

    <div class="border-t border-brand-ink/10 px-3 py-2.5 sm:px-4">
        <h4 class="text-sm font-semibold text-brand-ink">{{ __('Routing summary') }}</h4>
        <dl class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Server routes') }}</dt>
                <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $routingSummary['server_routes'] }}</dd>
            </div>
            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Project routes') }}</dt>
                <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $routingSummary['project_routes'] }}</dd>
            </div>
            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Available channels') }}</dt>
                <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $assignableChannels->count() }}</dd>
            </div>
        </dl>
    </div>
</div>
