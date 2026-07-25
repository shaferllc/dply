@props([
    /** @var \App\Support\Servers\ServerIndexRow $server */
    'server',
])

<div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
    <x-badge :tone="$server->statusTone" size="sm" :caps="false">
        <span class="me-1 inline-block h-1.5 w-1.5 rounded-full {{ $server->stripeClass }}" aria-hidden="true"></span>
        {{ $server->statusLabel }}
    </x-badge>

    <span class="inline-flex items-center gap-1.5">
        <x-credentials-provider-icon :provider="$server->provider" class="h-3.5 w-3.5 text-brand-mist" />
        {{ $server->providerLabel }}
    </span>

    @if ($server->workerLabel)
        <span class="text-brand-mist" aria-hidden="true">&middot;</span>
        <span class="inline-flex items-center gap-1 font-medium text-violet-700" title="{{ __('Worker server — background / queue capacity') }}">
            <x-heroicon-o-square-3-stack-3d class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ $server->workerLabel }}
        </span>
    @endif

    @if ($server->isFullyReady && $server->uptimeDays !== null)
        <span class="text-brand-mist" aria-hidden="true">&middot;</span>
        <span class="inline-flex items-center gap-1" title="{{ __('Uptime since creation') }}">
            <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
            {{ __('Online :days days', ['days' => $server->uptimeDays]) }}
        </span>
    @endif

    @if ($server->scheduledDeletionAt)
        <x-badge tone="warning" size="sm" :caps="false">
            <x-heroicon-m-clock class="me-1 h-3 w-3" aria-hidden="true" />
            {{ __('Removal :date', ['date' => $server->scheduledDeletionAt->timezone(config('app.timezone'))->toFormattedDateString()]) }}
        </x-badge>
    @endif
</div>
