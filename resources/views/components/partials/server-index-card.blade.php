@props([
    /** @var \App\Support\Servers\ServerIndexRow $server */
    'server',
    'layout' => 'list',
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
                    <a href="{{ $server->insightsHref ?? $server->manageHref }}" @if ($server->manageExternal || $server->insightsHref === null) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif title="{{ __('Open insights') }}" class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold leading-none {{ $server->insightsBadgeClass() }}">
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
                        <button type="button" wire:click="$set('tagFilter', @js($tag))" class="inline-flex items-center rounded-full bg-brand-sand/50 px-2 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10 transition hover:bg-brand-sage/15 hover:text-brand-ink">{{ $tag }}</button>
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

            <div class="mt-auto flex flex-wrap items-center justify-end gap-2 pt-1">
                @include('components.partials.server-index-actions', ['server' => $server, 'showMutations' => $showMutations, 'compact' => true])
            </div>
        </div>
    </li>
@else
    <li wire:key="server-list-{{ $server->id }}" class="flex items-stretch border-b border-brand-ink/10 transition-colors last:border-b-0 hover:bg-brand-sand/15">
        <div class="w-1 shrink-0 {{ $server->stripeClass }}" aria-hidden="true"></div>
        <div class="flex min-w-0 flex-1 flex-col gap-3 px-4 py-4 sm:px-6 lg:flex-row lg:items-center lg:gap-5">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <a href="{{ $server->manageHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="shrink-0" title="{{ $server->name }}">
                    <x-entity-avatar :seed="$server->name ?: $server->id" :image="$server->logoUrl" class="mt-0.5 h-9 w-9 text-sm" />
                </a>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <a href="{{ $server->manageHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">
                            {{ $server->name }}
                        </a>
                        <span class="font-mono text-xs text-brand-moss">{{ $server->ipAddress ?? __('Provisioning…') }}</span>
                        @if ($server->insightsOpen > 0)
                            <a href="{{ $server->insightsHref ?? $server->manageHref }}" @if ($server->manageExternal || $server->insightsHref === null) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif title="{{ __('Open insights') }}" class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold leading-none {{ $server->insightsBadgeClass() }}">
                                {{ trans_choice(':count insight|:count insights', $server->insightsOpen, ['count' => $server->insightsOpen]) }}
                            </a>
                        @endif
                    </div>

                    <div class="mt-2">
                        @include('components.partials.server-index-status-chips', ['server' => $server])
                    </div>

                    @if ($server->workspaceName)
                        @feature('surface.projects')
                            <p class="mt-1.5 text-xs text-brand-moss">
                                {{ __('Project:') }}
                                @if ($server->workspaceHref)
                                    <a href="{{ $server->workspaceHref }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">{{ $server->workspaceName }}</a>
                                @else
                                    <span class="font-medium text-brand-ink">{{ $server->workspaceName }}</span>
                                @endif
                            </p>
                        @endfeature
                    @endif

                    @if (count($server->tags) > 0)
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($server->tags as $tag)
                                <button type="button" wire:click="$set('tagFilter', @js($tag))" class="inline-flex items-center rounded-full bg-brand-sand/50 px-2 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10 transition hover:bg-brand-sage/15 hover:text-brand-ink">{{ $tag }}</button>
                            @endforeach
                        </div>
                    @endif

                    @include('components.partials.server-index-resource-tabs', ['server' => $server])

                    @if ($server->isSetupFailed)
                        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-red-700 ring-1 ring-red-200">
                                <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                                {{ __('Setup failed') }}
                            </span>
                            <span class="text-brand-ink">{{ __('Provisioning did not finish — open the journey to see the failing step.') }}</span>
                            @if ($server->journeyHref)
                                <a href="{{ $server->journeyHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="ml-auto inline-flex items-center gap-1 text-[11px] font-semibold text-red-700 hover:text-red-900">
                                    {{ __('Open journey') }}
                                    <x-heroicon-m-arrow-right class="h-3 w-3" />
                                </a>
                            @endif
                        </div>
                    @elseif ($server->provisioning)
                        @php $digest = $server->provisioning; @endphp
                        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-sky-800 ring-1 ring-sky-200">
                                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-sky-500"></span>
                                {{ $digest['phase_label'] }}
                            </span>
                            <span class="font-medium text-brand-ink">{{ $digest['step_label'] }}</span>
                            @if ($digest['step_index'] && $digest['step_total'])
                                <span class="text-brand-mist">·</span>
                                <span class="tabular-nums">{{ __('Step :i of :t', ['i' => $digest['step_index'], 't' => $digest['step_total']]) }}</span>
                            @endif
                            @if ($digest['elapsed_human'])
                                <span class="text-brand-mist">·</span>
                                <span class="tabular-nums">{{ __(':elapsed elapsed', ['elapsed' => $digest['elapsed_human']]) }}</span>
                            @endif
                            @if ($server->journeyHref)
                                <a href="{{ $server->journeyHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="ml-auto inline-flex items-center gap-1 text-[11px] font-semibold text-sky-700 hover:text-sky-900">
                                    {{ __('Open journey') }}
                                    <x-heroicon-m-arrow-right class="h-3 w-3" />
                                </a>
                            @endif
                        </div>
                        @if ($digest['step_index'] && $digest['step_total'])
                            @php $pct = max(0, min(100, (int) round(100 * $digest['step_index'] / $digest['step_total']))); @endphp
                            <div class="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-brand-ink/5">
                                <div class="h-full rounded-full bg-sky-500 transition-[width] duration-500" style="width: {{ $pct }}%"></div>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            <div class="hidden shrink-0 lg:block">
                @include('components.partials.server-index-metrics', ['metrics' => $server->metrics])
            </div>
            <div class="lg:hidden">
                @include('components.partials.server-index-metrics', ['metrics' => $server->metrics])
            </div>

            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                @include('components.partials.server-index-actions', ['server' => $server, 'showMutations' => $showMutations, 'compact' => false])
            </div>
        </div>
    </li>
@endif
