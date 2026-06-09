{{--
    Shared, scannable chip cluster for a server row (list + grid).

    Expects from the including scope:
      - $server                     the Server model
      - $statusTone($server)        x-badge tone string
      - $statusLabel($server)       human status label
      - $isFullyReady($server)      closure
--}}
<div class="flex flex-wrap items-center gap-1.5">
    <x-badge :tone="$statusTone($server)" size="sm" :caps="false">
        <span class="me-1 inline-block h-1.5 w-1.5 rounded-full {{ $stripe($server) }}" aria-hidden="true"></span>
        {{ $statusLabel($server) }}
    </x-badge>

    <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-brand-moss">
        <x-credentials-provider-icon :provider="$server->provider->value" class="h-3.5 w-3.5 text-brand-mist" />
        {{ $server->provider->label() }}
    </span>

    <span class="inline-flex items-center gap-1 text-xs text-brand-moss" title="{{ __('Sites on this server') }}">
        <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
        {{ trans_choice(':count site|:count sites', $server->sites_count, ['count' => $server->sites_count]) }}
    </span>

    @if ($isFullyReady($server))
        <span class="inline-flex items-center gap-1 text-xs text-brand-moss" title="{{ __('Uptime since creation') }}">
            <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
            {{ __('Online :days days', ['days' => max(0, (int) $server->created_at->diffInDays(now()))]) }}
        </span>
    @endif

    @if ($server->scheduled_deletion_at)
        <x-badge tone="warning" size="sm" :caps="false">
            <x-heroicon-m-clock class="me-1 h-3 w-3" aria-hidden="true" />
            {{ __('Removal :date', ['date' => $server->scheduled_deletion_at->timezone(config('app.timezone'))->toFormattedDateString()]) }}
        </x-badge>
    @endif
</div>
