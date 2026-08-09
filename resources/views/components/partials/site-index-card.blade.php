@props([
    /** @var \App\Support\Sites\SiteIndexRow $site */
    'site',
])

<li wire:key="site-{{ $site->id }}" class="flex items-stretch border-b border-brand-ink/10 transition-colors last:border-b-0 hover:bg-brand-sand/15">
    <div class="w-1 shrink-0 {{ $site->stripeClass }}" aria-hidden="true"></div>
    <div class="flex min-w-0 flex-1 flex-col gap-2.5 px-3 py-3.5 sm:px-5 sm:py-4">
        <div class="flex items-start gap-2.5 sm:gap-3">
            <a href="{{ $site->manageHref }}" @if ($site->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="hidden shrink-0 sm:block" title="{{ $site->name }}">
                <x-entity-avatar :seed="$site->name ?: $site->id" class="mt-0.5 h-9 w-9 text-sm" />
            </a>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <a href="{{ $site->manageHref }}" @if ($site->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="max-w-full truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">
                        {{ $site->name }}
                    </a>
                    @if ($site->frameworkLabel)
                        <span class="inline-flex items-center gap-1 rounded-full border border-brand-sage/30 bg-brand-sage/10 px-2 py-0.5 text-xs font-semibold text-brand-forest">
                            {{ $site->frameworkLabel }}
                        </span>
                    @endif
                    <span class="inline-flex items-center gap-1 rounded-full border border-brand-ink/10 bg-brand-sand/30 px-2 py-0.5 text-xs font-semibold text-brand-moss">
                        <x-heroicon-o-cpu-chip class="h-3 w-3 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ $site->typeLabel }}
                    </span>
                    <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-xs font-medium text-brand-moss">
                        {{ $site->runtimeExecutionModeLabel }}
                    </span>
                    @if ($site->phpVersion)
                        <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-xs font-medium text-brand-moss">
                            {{ __('PHP :v', ['v' => $site->phpVersion]) }}
                        </span>
                    @elseif ($site->runtimeVersion)
                        <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-xs font-medium text-brand-moss">
                            {{ ucfirst((string) ($site->runtimeKey ?? '')) }} {{ $site->runtimeVersion }}
                        </span>
                    @endif
                </div>

                <div class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-brand-moss">
                    @if ($site->primaryHostname)
                        <span class="inline-flex items-center gap-1 font-medium text-brand-ink">
                            <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ $site->primaryHostname }}
                        </span>
                        @if ($site->extraDomains > 0)
                            <span class="text-brand-mist">{{ trans_choice('+:count domain|+:count domains', $site->extraDomains, ['count' => $site->extraDomains]) }}</span>
                        @endif
                        <span class="text-brand-mist">·</span>
                    @endif
                    <span>{{ $site->runtimeProfileLabel }}</span>
                    @if ($site->deployStrategyLabel)
                        <span class="text-brand-mist">·</span>
                        <span>{{ $site->deployStrategyLabel }}</span>
                    @endif
                    @if ($site->workspaceName)
                        @feature('surface.projects')
                            <span class="text-brand-mist">·</span>
                            <span class="inline-flex items-center gap-1">
                                <x-heroicon-o-folder class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                @if ($site->workspaceHref)
                                    <a href="{{ $site->workspaceHref }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                                        {{ $site->workspaceName }}
                                    </a>
                                @else
                                    <span class="font-medium text-brand-ink">{{ $site->workspaceName }}</span>
                                @endif
                            </span>
                        @endfeature
                    @endif
                </div>

                @if ($site->serverName !== '' && $site->serverName !== '—')
                    @php
                        $serverLinkExternal = $site->manageExternal
                            || (is_string($site->serverHref) && str_starts_with($site->serverHref, 'http'));
                    @endphp
                    <div class="mt-1.5 text-sm">
                        <span class="inline-flex items-center gap-1.5 text-brand-moss">
                            <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                            <span class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Server') }}</span>
                            @if ($site->serverHref)
                                <a
                                    href="{{ $site->serverHref }}"
                                    @if ($serverLinkExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
                                    class="font-medium text-brand-ink hover:text-brand-sage hover:underline"
                                >
                                    {{ $site->serverName }}
                                </a>
                            @else
                                <span class="font-medium text-brand-ink">{{ $site->serverName }}</span>
                            @endif
                        </span>
                    </div>
                @endif

                @if ($site->gitRepoLabel || $site->gitBranch)
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                        @if ($site->gitRepoLabel)
                            <span class="inline-flex items-center gap-1 font-mono" title="{{ $site->gitRepoLabel }}">
                                <x-heroicon-o-code-bracket class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                <span class="max-w-[16rem] truncate">{{ $site->gitRepoLabel }}</span>
                            </span>
                        @endif
                        @if ($site->gitBranch)
                            <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-xs font-semibold text-brand-ink ring-1 ring-brand-ink/10">
                                <x-heroicon-o-hashtag class="h-3 w-3 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ $site->gitBranch }}
                            </span>
                        @endif
                    </div>
                @endif

                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                    @if ($site->lastDeployAt)
                        <span class="inline-flex items-center gap-1" title="{{ $site->lastDeployAt }}">
                            <x-heroicon-o-rocket-launch class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                            {{ __('Deployed :ago', ['ago' => $site->lastDeployAt->diffForHumans()]) }}
                        </span>
                    @endif
                    @if ($site->createdAt)
                        <span class="inline-flex items-center gap-1" title="{{ $site->createdAt }}">
                            <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                            {{ __('Created :ago', ['ago' => $site->createdAt->diffForHumans()]) }}
                        </span>
                    @endif
                </div>

                @if ($site->isProvisioning)
                    <p class="mt-1.5 text-xs text-amber-800">
                        {{ __('Provisioning step: :step', ['step' => str_replace('_', ' ', $site->provisioningState ?? 'queued')]) }}
                    </p>
                @elseif ($site->isFailed)
                    <p class="mt-1.5 text-xs text-red-700">
                        {{ $site->provisioningError ?: __('Provisioning failed.') }}
                    </p>
                @endif
            </div>

            <div class="flex shrink-0 flex-col items-end gap-2">
                <div class="flex flex-wrap items-center justify-end gap-1.5">
                    <x-badge size="sm" :tone="$site->statusTone">{{ $site->statusLabel }}</x-badge>
                    @if ($site->isProvisioning)
                        <x-badge size="sm" tone="warning">{{ __('Provisioning') }}</x-badge>
                    @endif
                    @if ($site->sslTone !== null)
                        <x-badge size="sm" :tone="$site->sslTone">{{ __('SSL: :status', ['status' => $site->sslStatus]) }}</x-badge>
                    @endif
                </div>
                <div class="flex flex-wrap items-center justify-end gap-1.5">
                    @include('components.partials.site-index-actions', ['site' => $site])
                </div>
            </div>
        </div>
    </div>
</li>
