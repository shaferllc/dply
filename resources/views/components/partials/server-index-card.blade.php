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
    <li wire:key="server-list-{{ $server->id }}" class="flex items-stretch border-b border-brand-ink/10 transition-colors last:border-b-0 hover:bg-brand-sand/15">
        <div class="w-1 shrink-0 {{ $server->stripeClass }}" aria-hidden="true"></div>
        <div class="flex min-w-0 flex-1 flex-col gap-1.5 px-3 py-2.5 sm:px-5 sm:py-3">
            {{-- Identity + actions share the top row so Manage never floats across a huge gap. --}}
            <div class="flex items-start gap-2.5 sm:gap-3">
                <a href="{{ $server->manageHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="hidden shrink-0 sm:block" title="{{ $server->name }}">
                    <x-entity-avatar :seed="$server->name ?: $server->id" :image="$server->logoUrl" class="mt-0.5 h-8 w-8 text-xs" />
                </a>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <a href="{{ $server->manageHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="max-w-full truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">
                            {{ $server->name }}
                        </a>
                        <span class="max-w-[11rem] truncate font-mono text-xs text-brand-moss sm:max-w-none">{{ $server->ipAddress ?? __('Provisioning…') }}</span>
                        @if ($server->insightsOpen > 0)
                            <a href="{{ $server->insightsHref ?? $server->manageHref }}" @if ($server->manageExternal || $server->insightsHref === null) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif title="{{ __('Open insights') }}" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold leading-none {{ $server->insightsBadgeClass() }}">
                                {{ trans_choice(':count insight|:count insights', $server->insightsOpen, ['count' => $server->insightsOpen]) }}
                            </a>
                        @endif
                    </div>

                    <div class="mt-1">
                        @include('components.partials.server-index-status-chips', ['server' => $server])
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5 sm:gap-2">
                    @include('components.partials.server-index-actions', ['server' => $server, 'showDeployActions' => $showDeployActions, 'showMutations' => $showMutations, 'compact' => true, 'responsive' => true])
                </div>
            </div>

            {{-- Project, tags and the metric chips are all short inline runs. They
                 used to stack as three separate rows, which cost ~60px per server
                 for content that fits on one line at any realistic width. Always
                 rendered: the metrics partial has its own "No metrics" state,
                 which is the signal that monitoring isn't installed. --}}
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 sm:ps-11">
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

                @foreach ($server->tags as $tag)
                    <button type="button" wire:click="$set('tagFilter', @js($tag))" class="inline-flex items-center rounded-full bg-brand-sand/50 px-2 py-0.5 text-2xs font-semibold text-brand-moss ring-1 ring-brand-ink/10 transition hover:bg-brand-sage/15 hover:text-brand-ink">{{ $tag }}</button>
                @endforeach

                @include('components.partials.server-index-metrics', ['metrics' => $server->metrics])
            </div>

            <div class="sm:ps-11">
                @include('components.partials.server-index-resource-tabs', ['server' => $server])
            </div>

            @if ($server->isSetupFailed)
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss sm:ps-11">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.16em] text-red-700 ring-1 ring-red-200">
                        <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                        {{ __('Setup failed') }}
                    </span>
                    <span class="text-brand-ink">{{ __('Provisioning did not finish — open the journey to see the failing step.') }}</span>
                    @if ($server->journeyHref)
                        <a href="{{ $server->journeyHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="inline-flex items-center gap-1 text-xs font-semibold text-red-700 hover:text-red-900 sm:ms-auto">
                            {{ __('Open journey') }}
                            <x-heroicon-m-arrow-right class="h-3 w-3" />
                        </a>
                    @endif
                </div>
            @elseif ($server->adopted)
                {{-- Adopted hosts never run a provisioning journey, so say what
                     dply found on the box instead of advertising setup that is
                     never coming. --}}
                @php $adopted = $server->adopted; @endphp
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss sm:ps-11">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sage/15 px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-forest ring-1 ring-brand-sage/25">
                        @if ($adopted['state'] === 'scanning')
                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-brand-sage"></span>
                        @else
                            <x-heroicon-m-check class="h-3 w-3" aria-hidden="true" />
                        @endif
                        {{ $adopted['label'] }}
                    </span>
                    @if ($adopted['detail'])
                        <span class="min-w-0 text-brand-ink">{{ $adopted['detail'] }}</span>
                    @endif
                    <a href="{{ $server->manageHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline sm:ms-auto">
                        {{ __('Review') }}
                        <x-heroicon-m-arrow-right class="h-3 w-3" />
                    </a>
                </div>
            @elseif ($server->provisioning)
                @php $digest = $server->provisioning; @endphp
                <div class="sm:ps-11">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.16em] text-sky-800 ring-1 ring-sky-200">
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
                            <a href="{{ $server->journeyHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="inline-flex items-center gap-1 text-xs font-semibold text-sky-700 hover:text-sky-900 sm:ms-auto">
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
                </div>
            @endif
        </div>
    </li>
@endif
