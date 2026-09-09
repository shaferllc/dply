@props([
    /** @var \App\Support\Servers\ServerIndexRow $server */
    'server',
    'layout' => 'list',
    'showDeployActions' => false,
    'showMutations' => false,
])

@if ($layout === 'grid')
    <li wire:key="server-grid-{{ $server->id }}" class="flex overflow-hidden rounded-xl border border-brand-ink/10 bg-white shadow-sm transition-colors hover:border-brand-ink/20">
        <div class="w-1 shrink-0 {{ $server->stripeClass }}" aria-hidden="true"></div>
        <div class="flex min-w-0 flex-1 flex-col gap-3 p-4">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <a href="{{ $server->manageHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="block truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">{{ $server->name }}</a>
                    <p class="mt-0.5 truncate font-mono text-xs text-brand-moss">{{ $server->ipAddress ?? __('Provisioning…') }}</p>
                </div>
                @if ($server->insightsOpen > 0)
                    <a href="{{ $server->insightsHref ?? $server->manageHref }}" @if ($server->manageExternal || $server->insightsHref === null) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif title="{{ __('Open insights') }}" class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-semibold leading-none {{ $server->insightsBadgeClass() }}">
                        {{ trans_choice(':count insight|:count insights', $server->insightsOpen, ['count' => $server->insightsOpen]) }}
                    </a>
                @endif
            </div>

            @include('components.partials.server-index-status-chips', ['server' => $server])

            <div>
                @include('components.partials.server-index-metrics', ['metrics' => $server->metrics])
            </div>

            @if (count($server->tags) > 0)
                <div class="flex flex-wrap gap-1">
                    @foreach ($server->tags as $tag)
                        <button type="button" wire:click="$set('tagFilter', @js($tag))" class="inline-flex items-center rounded-full bg-brand-sand/50 px-2 py-0.5 text-2xs font-semibold text-brand-moss ring-1 ring-brand-ink/10 transition hover:bg-brand-sage/15 hover:text-brand-ink">{{ $tag }}</button>
                    @endforeach
                </div>
            @endif

            @if ($server->workspaceName)
                @feature('surface.projects')
                    <p class="text-xs text-brand-moss">
                        {{ __('Project:') }}
                        @if ($server->workspaceHref)
                            <a href="{{ $server->workspaceHref }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">{{ $server->workspaceName }}</a>
                        @else
                            <span class="font-medium text-brand-ink">{{ $server->workspaceName }}</span>
                        @endif
                    </p>
                @endfeature
            @endif

            @include('components.partials.server-index-resource-tabs', ['server' => $server])

            <div class="mt-auto flex flex-wrap items-center justify-stretch gap-2 pt-1 sm:justify-end">
                @include('components.partials.server-index-actions', ['server' => $server, 'showDeployActions' => $showDeployActions, 'showMutations' => $showMutations, 'compact' => true])
            </div>
        </div>
    </li>
@else
@php
    $dotTone = $server->isSetupFailed || $server->needsAttention
        ? 'bg-red-600'
        : ($server->isFullyReady ? 'bg-brand-sage' : 'bg-brand-gold');
@endphp
{{-- One row per host: name, IP, status, sites, project. Metrics, tags, resource
     tabs and the provisioning digest live on the server workspace — the fleet
     list only answers "which host, is it healthy, take me there". --}}
<tr wire:key="server-list-{{ $server->id }}" class="group border-b border-brand-ink/10 transition-colors last:border-b-0 hover:bg-brand-sand/15">
    <td class="max-w-[16rem] px-3 py-2.5 sm:px-4">
        <div class="flex items-center gap-2">
            <a
                href="{{ $server->manageHref }}"
                @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
                class="min-w-0 truncate font-semibold text-brand-ink transition-colors hover:text-brand-sage"
                title="{{ $server->name }}"
            >
                {{ $server->name }}
            </a>
            @if ($server->insightsOpen > 0)
                <a
                    href="{{ $server->insightsHref ?? $server->manageHref }}"
                    @if ($server->manageExternal || $server->insightsHref === null) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
                    title="{{ __('Open insights') }}"
                    class="inline-flex shrink-0 items-center rounded-full px-1.5 py-0.5 text-2xs font-semibold leading-none {{ $server->insightsBadgeClass() }}"
                >
                    {{ $server->insightsOpen }}
                </a>
            @endif
        </div>
    </td>

    <td class="hidden whitespace-nowrap px-3 py-2.5 font-mono text-xs text-brand-moss sm:table-cell sm:px-4">
        {{ $server->ipAddress ?? __('Provisioning…') }}
    </td>

    <td class="max-w-[14rem] px-3 py-2.5 sm:px-4">
        <span class="flex min-w-0 items-center gap-1.5 text-brand-moss">
            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotTone }}" aria-hidden="true"></span>
            @if ($server->isSetupFailed)
                <span class="truncate text-red-700">{{ __('Setup failed') }}</span>
                @if ($server->journeyHref)
                    <a href="{{ $server->journeyHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="shrink-0 text-xs font-semibold text-red-700 hover:underline">{{ __('Journey') }}</a>
                @endif
            @elseif ($server->provisioning)
                <span class="truncate" title="{{ $server->provisioning['step_label'] }}">{{ $server->provisioning['phase_label'] }}</span>
                @if ($server->provisioning['step_index'] && $server->provisioning['step_total'])
                    <span class="shrink-0 tabular-nums text-brand-mist">{{ $server->provisioning['step_index'] }}/{{ $server->provisioning['step_total'] }}</span>
                @endif
            @else
                <span class="truncate">{{ $server->statusLabel }}</span>
            @endif
        </span>
    </td>

    <td class="whitespace-nowrap px-3 py-2.5 font-mono tabular-nums text-brand-moss sm:px-4">{{ $server->sitesCount }}</td>

    <td class="hidden max-w-[12rem] px-3 py-2.5 text-brand-moss sm:px-4 lg:table-cell">
        @if ($server->workspaceName)
            @feature('surface.projects')
                @if ($server->workspaceHref)
                    <a href="{{ $server->workspaceHref }}" wire:navigate class="block truncate transition-colors hover:text-brand-ink">{{ $server->workspaceName }}</a>
                @else
                    <span class="block truncate">{{ $server->workspaceName }}</span>
                @endif
            @endfeature
        @else
            <span class="text-brand-mist">—</span>
        @endif
    </td>

    <td class="px-3 py-2.5 sm:px-4">
        <div class="flex items-center justify-end gap-1.5 transition-opacity focus-within:opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
            @include('components.partials.server-index-actions', ['server' => $server, 'showDeployActions' => $showDeployActions, 'showMutations' => $showMutations, 'compact' => true, 'responsive' => true])
        </div>
    </td>
</tr>
@endif
