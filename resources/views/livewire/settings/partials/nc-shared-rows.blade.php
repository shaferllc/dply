{{-- One channel row, shared by the variants that render a plain table.
     Expects $channel, $canManage, $eventNamesFor. --}}
@php($destination = $channel->describeDestination())
<td class="px-3 py-2 sm:px-4">
    <div class="flex min-w-0 items-center gap-2">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-brand-sand/45 text-brand-moss ring-1 ring-brand-ink/10">
            <x-dynamic-component :component="\App\Models\NotificationChannel::iconForType($channel->type)" class="h-3.5 w-3.5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="truncate font-semibold text-brand-ink">{{ $channel->label }}</span>
                @if ($channel->isPaging())
                    <span class="inline-flex shrink-0 items-center gap-0.5 rounded bg-rose-50 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-rose-700 ring-1 ring-rose-200" title="{{ __('Alerts sent here page whoever is on call.') }}">
                        <x-heroicon-m-bell-alert class="h-3 w-3" aria-hidden="true" />
                        {{ __('Pages on-call') }}
                    </span>
                @endif
            </div>
            @if ($destination !== '')
                <p class="mt-px truncate font-mono text-2xs text-brand-mist" title="{{ $destination }}">{{ $destination }}</p>
            @endif
        </div>
    </div>
</td>
<td class="px-3 py-2 text-xs text-brand-moss">
    {{ \App\Models\NotificationChannel::labelForType($channel->type) }}
</td>
<td class="px-3 py-2 text-xs">
    @if ($channel->subscriptions_count > 0)
        @php($eventNames = $eventNamesFor($channel))
        <span class="text-brand-moss" title="{{ $eventNames->implode(', ') }}">
            {{ trans_choice(':n event|:n events', $channel->subscriptions_count, ['n' => $channel->subscriptions_count]) }}
        </span>
    @else
        <a
            href="{{ route('profile.notification-channels.bulk-assign') }}?channel={{ $channel->id }}"
            wire:navigate
            class="inline-flex items-center gap-0.5 rounded bg-amber-50 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200 transition-colors hover:bg-amber-100"
            title="{{ __('Subscribed to nothing — it will never fire.') }}"
        >
            <x-heroicon-m-exclamation-triangle class="h-3 w-3" aria-hidden="true" />
            {{ __('Not routed') }}
        </a>
    @endif
</td>
<td class="px-3 py-2 text-right sm:px-4">
    @if ($canManage)
        @php($act = 'inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border px-2 text-xs font-semibold shadow-sm transition-colors')
        <div class="inline-flex flex-nowrap items-center gap-1.5">
            <button type="button" wire:click="sendTest('{{ $channel->id }}')" wire:loading.attr="disabled" wire:target="sendTest" class="{{ $act }} border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40">
                <x-heroicon-o-paper-airplane class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Test') }}
            </button>
            <button type="button" wire:click="startEdit('{{ $channel->id }}')" class="{{ $act }} border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40">
                <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Edit') }}
            </button>
            <button type="button" wire:click="openConfirmActionModal('deleteChannel', ['{{ $channel->id }}'], @js(__('Delete notification channel')), @js(__('Remove this channel?')), @js(__('Delete')), true)" class="{{ $act }} border-rose-200 bg-white text-rose-700 hover:bg-rose-50">
                <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Delete') }}
            </button>
        </div>
    @endif
</td>
