@php
    /** @var \Illuminate\Support\Collection $notifSubscriptions */
    /** @var \Illuminate\Support\Collection $notifChannels */
    /** @var array<string, string> $notifEventLabels */
    $subscriptionsByChannel = $notifSubscriptions->groupBy('notification_channel_id');
@endphp

<div class="min-w-0">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-bell"
        :title="__('Maintenance alerts')"
        :note="__('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s maintenance events — when a window is enabled, ended, or auto-ends after its scheduled time. Each row binds one channel to one event.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <x-secondary-button size="xs" href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}" wire:navigate class="whitespace-nowrap">
                {{ __('Manage in Settings') }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </x-secondary-button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="flex items-start gap-2 border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-2.5 text-xs leading-relaxed text-brand-moss sm:px-6">
        <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
        <p>{{ __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever a maintenance window is enabled or ended — no setup needed. Add a channel below only to also send email / chat / webhook alerts.') }}</p>
    </div>

    {{-- Current subscriptions --}}
    <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
        @if ($subscriptionsByChannel->isEmpty())
            <div class="border border-dashed border-brand-ink/15 bg-brand-sand/15 p-4 text-center">
                <x-heroicon-o-bell-slash class="mx-auto h-8 w-8 text-brand-mist" aria-hidden="true" />
                <p class="mt-3 text-sm text-brand-moss">
                    {{ __('No external channels are routed for maintenance events yet.') }}
                </p>
                <p class="mt-1 text-xs text-brand-mist">
                    {{ __('Add one below to get an email or chat message when this server enters or leaves maintenance.') }}
                </p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10 border border-brand-ink/10 bg-white">
                @foreach ($subscriptionsByChannel as $channelId => $subs)
                    @php $channel = $subs->first()->channel; @endphp
                    <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="maint-notif-ch-{{ $channelId }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                            <p class="text-xs text-brand-moss">{{ ucfirst((string) ($channel?->type ?? '—')) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach ($subs as $sub)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2 py-1 text-xs font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10" wire:key="maint-notif-sub-{{ $sub->id }}">
                                    {{ $notifEventLabels[$sub->event_key] ?? $sub->event_key }}
                                    <button
                                        type="button"
                                        wire:click="removeMaintenanceNotificationSubscription(@js($sub->id))"
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
</div>
