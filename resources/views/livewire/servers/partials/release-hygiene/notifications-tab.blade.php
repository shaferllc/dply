@php
    /** @var \Illuminate\Support\Collection $notifSubscriptions */
    /** @var \Illuminate\Support\Collection $notifChannels */
    /** @var array<string, string> $notifEventLabels */
    $subscriptionsByChannel = $notifSubscriptions->groupBy('notification_channel_id');
@endphp

{{-- Nested inside the merged Hygiene card — same chrome as Health Notifications. --}}
<div>
    <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-4 sm:px-6">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-bell class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Notifications') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Release hygiene alerts') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s release hygiene — disk pressure, oversized logs, extra release folders, and failed jobs. Each row binds one channel to one event.') }}
                </p>
            </div>
        </div>
        <a
            href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}"
            wire:navigate
            class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
        >
            {{ __('Manage in Settings') }}
            <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        </a>
    </div>

    <p class="border-b border-brand-ink/10 px-5 py-3 text-xs leading-relaxed text-brand-moss sm:px-6">
        {{ __('Findings are evaluated when hygiene is scanned — manually via Scan disk, and automatically on the daily sweep. Alerts only fire when pressure worsens into a warning / critical state or recovers, so you won\'t get repeats for the same standing issue. Add a channel below to send email / chat / webhook alerts.') }}
    </p>

    <div class="px-5 py-5 sm:px-6">
        @if ($subscriptionsByChannel->isEmpty())
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 p-6 text-center">
                <x-heroicon-o-bell-slash class="mx-auto h-8 w-8 text-brand-mist" aria-hidden="true" />
                <p class="mt-3 text-sm text-brand-moss">
                    {{ __('No external channels are routed for release hygiene events yet.') }}
                </p>
                <p class="mt-1 text-xs text-brand-mist">
                    {{ __('Add one below to get an email or chat message when this server\'s release or disk pressure degrades.') }}
                </p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                @foreach ($subscriptionsByChannel as $channelId => $subs)
                    @php $channel = $subs->first()->channel; @endphp
                    <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="hygiene-notif-ch-{{ $channelId }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                            <p class="text-xs text-brand-moss">{{ ucfirst((string) ($channel?->type ?? '—')) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach ($subs as $sub)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2 py-1 text-xs font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10" wire:key="hygiene-notif-sub-{{ $sub->id }}">
                                    {{ $notifEventLabels[$sub->event_key] ?? $sub->event_key }}
                                    <button
                                        type="button"
                                        wire:click="removeReleaseHygieneNotificationSubscription(@js($sub->id))"
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
