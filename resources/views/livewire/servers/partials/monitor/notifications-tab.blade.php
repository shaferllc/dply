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
    {{-- Shared channel matrix, same partial and same save path as the central
         Server notifications page. Replaces a bespoke "Add a channel" form that
         wrote the same subscription rows through a different interaction model.
         Reconciliation is scoped to this tab's own event keys, so saving here
         cannot disturb a channel's other subscriptions. --}}
    <div class="border-t border-brand-ink/10 px-5 py-3 sm:px-6">
        @include('livewire.partials.notification-channel-matrix', [
            'channels' => $this->assignableMatrixChannels(),
            'eventGroups' => $this->featureEventGroups(),
            'selections' => $channelEventSelections,
            'model' => 'channelEventSelections',
            'showFilter' => false,
        ])
    </div>

    <div class="flex flex-col gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <p class="text-xs text-brand-mist">{{ __('Each channel routes its own events. Removing = untick; channels not shown here are never changed.') }}</p>
        <x-primary-button size="xs" type="button" wire:click="saveFeatureNotificationSubscriptions" wire:loading.attr="disabled" wire:target="saveFeatureNotificationSubscriptions">
            <span wire:loading.remove wire:target="saveFeatureNotificationSubscriptions">{{ __('Save subscriptions') }}</span>
            <span wire:loading wire:target="saveFeatureNotificationSubscriptions">{{ __('Saving…') }}</span>
        </x-primary-button>
    </div>
    @endif
</div>
