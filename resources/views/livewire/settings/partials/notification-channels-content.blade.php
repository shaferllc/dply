{{-- The channels page body: connected apps as one chip row, then the list.
     The three provider panels (Slack / Discord / Telegram), the stats console,
     and the separate "available beyond your personal channels" card are gone —
     connections are a prerequisite, not a peer of the list, and the inherited
     count is a phrase, not a section. --}}
@php
    $hasChannelSearch = trim($search ?? '') !== '';
    $channelTotal = $channels->count();
    $eventLabels = collect(config('notification_events.categories', []))
        ->flatMap(fn (array $category): array => $category['events'] ?? [])->all();
    $eventNamesFor = fn ($channel) => $channel->subscriptions->pluck('event_key')->unique()
        ->map(fn (string $k): string => $eventLabels[$k] ?? $k)->values();

    $slack = $canManage ? $this->slackInstallations() : collect();
    $discord = $canManage ? $this->discordInstallations() : collect();
    $inheritedCount = (isset($organizationChannels) ? $organizationChannels->count() : 0)
        + ($teamChannelGroups ?? collect())->sum(fn ($e) => $e['channels']->count());
@endphp

{{-- On the settings layout the trail is hoisted above the nav + grid via the
     stack; in the org shell it is passed to x-organization-shell's :breadcrumb.
     Rendering it inline here puts it *inside* the card, under the panel header. --}}
@if (! empty($breadcrumbs) && empty($useOrgShell))
    @push('breadcrumbs')
        <x-breadcrumb-trail doc-route="docs.index" :items="$breadcrumbs" />
    @endpush
@endif

<x-livewire-validation-errors />

{{-- Connections as chips: name, and one disconnect. No card per provider. --}}
@if ($canManage && ($slack->isNotEmpty() || $discord->isNotEmpty() || $this->slackOauthConfigured()))
    <div class="flex flex-wrap items-center gap-1.5 border-b border-brand-ink/10 bg-brand-cream/40 px-3 py-2 sm:px-4">
        <span class="me-1 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Connected') }}</span>
        @foreach ($slack as $workspace)
            <span class="group inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm">
                <x-heroicon-o-chat-bubble-left-right class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                {{ $workspace->team_name }}
                <button type="button" wire:click="disconnectSlackWorkspace('{{ $workspace->id }}')"
                    wire:confirm="{{ __('Disconnect :team? Channels pointed at it stop delivering.', ['team' => $workspace->team_name]) }}"
                    class="ms-0.5 text-brand-mist transition-colors hover:text-rose-700" title="{{ __('Disconnect') }}">
                    <x-heroicon-m-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </span>
        @endforeach
        @foreach ($discord as $guild)
            <span class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm">
                <x-heroicon-o-hashtag class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                {{ $guild->guild_name ?? __('Discord server') }}
            </span>
        @endforeach
        @if ($this->slackOauthConfigured())
            <a href="{{ $this->slackConnectUrl() }}"
               x-on:click.prevent="window.location.href = @js($this->slackConnectUrl()) + '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
               class="inline-flex h-6 items-center gap-1 rounded-md border border-dashed border-brand-ink/20 bg-white px-2 text-xs font-semibold text-brand-moss transition hover:bg-brand-sand/40 hover:text-brand-ink">
                <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ $slack->isEmpty() ? __('Add to Slack') : __('Add workspace') }}
            </a>
        @endif
        <span class="ms-auto text-xs text-brand-mist">{{ __('Connect once, then pick rooms instead of pasting webhooks.') }}</span>
    </div>
@endif

<div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
    <span class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Channels') }}</span>
    <span class="font-mono text-xs tabular-nums text-brand-moss">{{ $channelTotal }}</span>
    @if ($inheritedCount > 0)
        <span class="text-xs text-brand-mist">{{ __('+ :n inherited from org and teams', ['n' => $inheritedCount]) }}</span>
    @endif
    @if ($channelTotal > 8 || $hasChannelSearch)
        <div class="ms-auto w-full sm:w-56">
            <label for="nc_v3_search" class="sr-only">{{ __('Search') }}</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                    <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
                </span>
                <input id="nc_v3_search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search channels…') }}" autocomplete="off"
                    class="h-6 w-full rounded-md border-brand-ink/15 bg-white py-0 ps-8 pe-2.5 text-xs text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage" />
            </div>
        </div>
    @endif
</div>

@if ($channels->isEmpty())
    <div class="px-3 py-10 text-center sm:px-4">
        <p class="text-sm font-medium text-brand-ink">
            {{ $hasChannelSearch ? __('No channels match this search.') : __('No notification channels yet.') }}
        </p>
        @if ($hasChannelSearch)
            <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
        @elseif ($canManage && count($types) > 0)
            <button type="button" wire:click="openCreateChannelModal" class="mt-3 inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Add channel') }}
            </button>
        @endif
    </div>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-brand-ink/10">
                @foreach ($pagedChannels as $channel)
                    <tr wire:key="nc3-{{ $channel->id }}" class="transition-colors hover:bg-brand-sand/15">
                        @include('livewire.settings.partials.nc-shared-rows')
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($channelPages > 1)
        <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2 sm:px-4">
            <p class="text-xs text-brand-moss">{{ __('Page :page of :pages', ['page' => $this->channelPage, 'pages' => $channelPages]) }}</p>
            <div class="flex items-center gap-1.5">
                <button type="button" wire:click="$set('channelPage', {{ max(1, $this->channelPage - 1) }})" @disabled($this->channelPage <= 1) class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm disabled:opacity-40">{{ __('Previous') }}</button>
                <button type="button" wire:click="$set('channelPage', {{ min($channelPages, $this->channelPage + 1) }})" @disabled($this->channelPage >= $channelPages) class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm disabled:opacity-40">{{ __('Next') }}</button>
            </div>
        </div>
    @endif
@endif

@include('livewire.settings.partials.nc-modals')
