@props([
    /** @var \App\Support\Sites\SiteIndexRow $site */
    'site',
])

@php
    $dotTone = $site->isFailed
        ? 'bg-red-600'
        : ($site->isProvisioning ? 'bg-brand-gold' : ($site->isReadyForTraffic ? 'bg-brand-sage' : 'bg-brand-mist'));
@endphp

{{-- One row per site: name, hostname, status, server, last deploy. Runtime and
     framework pills, git ref and SSL live on the site workspace — the index only
     answers "which site, is it up, take me there". --}}
<tr wire:key="site-{{ $site->id }}" class="group border-b border-brand-ink/10 transition-colors last:border-b-0 hover:bg-brand-sand/15">
    <td class="max-w-[16rem] px-3 py-2.5 sm:px-5">
        <a
            href="{{ $site->manageHref }}"
            @if ($site->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
            class="block truncate font-semibold text-brand-ink transition-colors hover:text-brand-sage"
            title="{{ $site->name }}"
        >
            {{ $site->name }}
        </a>
    </td>

    <td class="max-w-[18rem] px-3 py-2.5 text-brand-moss sm:px-5">
        @if ($site->primaryHostname)
            <span class="block truncate" title="{{ $site->primaryHostname }}">
                {{ $site->primaryHostname }}
                @if ($site->extraDomains > 0)
                    <span class="text-brand-mist">+{{ $site->extraDomains }}</span>
                @endif
            </span>
        @else
            <span class="text-brand-mist">{{ __('No domain yet') }}</span>
        @endif
    </td>

    <td class="whitespace-nowrap px-3 py-2.5 sm:px-5">
        <span class="inline-flex items-center gap-1.5 text-brand-moss" @if ($site->isFailed && $site->provisioningError) title="{{ $site->provisioningError }}" @endif>
            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotTone }}" aria-hidden="true"></span>
            {{ $site->isProvisioning ? __('Provisioning') : $site->statusLabel }}
        </span>
    </td>

    <td class="hidden max-w-[12rem] px-3 py-2.5 text-brand-moss sm:px-5 lg:table-cell">
        @if ($site->serverHref)
            <a
                href="{{ $site->serverHref }}"
                @if ($site->manageExternal || (is_string($site->serverHref) && str_starts_with($site->serverHref, 'http'))) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
                class="block truncate transition-colors hover:text-brand-ink"
            >
                {{ $site->serverName }}
            </a>
        @else
            <span class="block truncate">{{ $site->serverName }}</span>
        @endif
    </td>

    <td class="hidden whitespace-nowrap px-3 py-2.5 text-brand-mist sm:table-cell sm:px-5">
        @if ($site->lastDeployAt)
            <span title="{{ $site->lastDeployAt }}">{{ $site->lastDeployAt->diffForHumans(short: true) }}</span>
        @else
            —
        @endif
    </td>

    <td class="px-3 py-2.5 sm:px-5">
        <div class="flex items-center justify-end gap-1.5 transition-opacity focus-within:opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
            @include('components.partials.site-index-actions', ['site' => $site])
        </div>
    </td>
</tr>
