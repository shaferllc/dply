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
