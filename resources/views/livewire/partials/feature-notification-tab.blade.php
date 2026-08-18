@php
    /**
     * Shared chrome for every feature Notifications tab (Logs, Firewall, Health, …).
     *
     * @var string $title
     * @var string $note
     * @var string $explainer
     * @var string $empty
     * @var \Illuminate\Support\Collection $notifSubscriptions
     * @var array<string, string> $notifEventLabels
     * @var string $listKey
     * @var string $settingsHref
     * @var string $settingsLabel
     * @var bool $canEdit
     */
    $notifSubscriptions = $notifSubscriptions ?? collect();
    $notifEventLabels = $notifEventLabels ?? [];
    $subscriptionsByChannel = $notifSubscriptions->groupBy('notification_channel_id');
    $listKey = $listKey ?? 'feat';
    $settingsHref = $settingsHref ?? route('profile.notification-channels.bulk-assign', ['server' => $server->id]);
    $settingsLabel = $settingsLabel ?? __('Manage in Settings');
    $canEdit = $canEdit ?? true;
@endphp

<div class="min-w-0">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-bell"
        :title="$title"
        :note="$note"
        :count="trans_choice('{0} none routed|{1} :count route|[2,*] :count routes', $notifSubscriptions->count(), ['count' => $notifSubscriptions->count()])"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <x-secondary-button size="xs" href="{{ $settingsHref }}" wire:navigate class="shrink-0 whitespace-nowrap">
                {{ $settingsLabel }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </x-secondary-button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="flex items-start gap-2.5 border-b border-brand-ink/10 bg-brand-sand/15 px-4 py-2.5 text-xs leading-relaxed text-brand-moss sm:px-5">
        <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
        <p>{{ $explainer }}</p>
    </div>

    @if ($subscriptionsByChannel->isEmpty())
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-brand-ink/10 px-4 py-2.5 text-xs text-brand-moss sm:px-5">
            <x-heroicon-m-bell-slash class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
            {{ $empty }}
        </p>
    @else
        <ul class="divide-y divide-brand-ink/10 border-b border-brand-ink/10">
            @foreach ($subscriptionsByChannel as $channelId => $subs)
                @php $channel = $subs->first()->channel; @endphp
                <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2 sm:px-5" wire:key="{{ $listKey }}-notif-ch-{{ $channelId }}">
                    <p class="min-w-0 truncate text-xs font-semibold text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                    <p class="shrink-0 text-xs text-brand-mist">{{ ucfirst((string) ($channel?->type ?? '—')) }}</p>
                    <div class="ml-auto flex flex-wrap items-center gap-1.5">
                        @foreach ($subs as $sub)
                            <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-xs font-semibold text-brand-ink ring-1 ring-inset ring-brand-ink/10" wire:key="{{ $listKey }}-notif-sub-{{ $sub->id }}">
                                {{ $notifEventLabels[$sub->event_key] ?? $sub->event_key }}
                            </span>
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canEdit)
        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
            @include('livewire.partials.notification-channel-matrix', [
                'channels' => $this->assignableMatrixChannels(),
                'eventGroups' => $this->featureEventGroups(),
                'selections' => $channelEventSelections,
                'model' => 'channelEventSelections',
                'showFilter' => false,
            ])
        </div>

        <div class="flex flex-col gap-2 bg-brand-sand/25 px-5 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <p class="text-xs text-brand-mist">{{ __('Each channel routes its own events. Removing = untick; channels not shown here are never changed.') }}</p>
            <x-primary-button size="xs" type="button" wire:click="saveFeatureNotificationSubscriptions" wire:loading.attr="disabled" wire:target="saveFeatureNotificationSubscriptions">
                <span wire:loading.remove wire:target="saveFeatureNotificationSubscriptions">{{ __('Save subscriptions') }}</span>
                <span wire:loading wire:target="saveFeatureNotificationSubscriptions">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    @endif
</div>
