@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    ];

    // Container hosts (docker/kubernetes) don't run the VM-shaped setup journey,
    // so they're "setup complete" the moment they're STATUS_READY — even when
    // setup_status is null.
    $isContainerHost = in_array($server->hostKind(), [\App\Models\Server::HOST_KIND_DOCKER, \App\Models\Server::HOST_KIND_KUBERNETES], true);
    $setupIncomplete = $server->isVmHost() && (
        $server->status !== \App\Models\Server::STATUS_READY
        || $server->setup_status !== \App\Models\Server::SETUP_STATUS_DONE
    );
    $containerLaunchTranscript = collect($containerLaunch['events'] ?? [])->map(function (array $event): string {
        $timestamp = (string) ($event['at'] ?? '');
        $level = strtoupper((string) ($event['level'] ?? 'info'));
        $message = (string) ($event['message'] ?? 'Container launch update');
        $lines = [];

        $prefixParts = array_values(array_filter([$timestamp, $level]));
        $lines[] = ($prefixParts !== [] ? '['.implode('] [', $prefixParts).'] ' : '').$message;

        foreach (collect($event['context'] ?? [])->filter(fn ($value) => ! is_array($value)) as $contextKey => $contextValue) {
            $rendered = is_bool($contextValue) ? ($contextValue ? 'true' : 'false') : (string) $contextValue;
            if ($rendered === '') {
                continue;
            }

            $lines[] = '  > '.str_replace('_', ' ', (string) $contextKey).': '.$rendered;
        }

        return implode("\n", $lines);
    })->implode("\n\n");
@endphp

<x-server-workspace-layout
    :server="$server"
    active="overview"
    :title="__('Overview')"
    :show-navigation="! $setupIncomplete"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @php
        $provisionError = is_array($server->meta['provision_error'] ?? null) ? $server->meta['provision_error'] : null;
    @endphp

    <div class="space-y-6">
        {{-- Provisioning error banner --}}
        @if ($provisionError && $server->status === \App\Models\Server::STATUS_ERROR)
            <section data-testid="server-provision-error" class="dply-card overflow-hidden border-rose-200">
                <div class="border-b border-brand-ink/10 bg-rose-50/70 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['rose'] }}">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Provisioning error') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ __('Provisioning failed at :provider', ['provider' => $provisionError['provider'] ?? 'the provider']) }}
                            </h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $provisionError['message'] ?? __('Unknown error.') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-brand-moss">
                                @if (! empty($provisionError['region']))
                                    <span><strong class="text-brand-ink">{{ __('Region') }}:</strong> {{ $provisionError['region'] }}</span>
                                @endif
                                @if (! empty($provisionError['size']))
                                    <span><strong class="text-brand-ink">{{ __('Size') }}:</strong> {{ $provisionError['size'] }}</span>
                                @endif
                                @if (! empty($provisionError['at']))
                                    <span><strong class="text-brand-ink">{{ __('At') }}:</strong> {{ $provisionError['at'] }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        {{-- K8s cluster gone away --}}
        @if (! empty($kubernetesError))
            <section data-testid="kubernetes-cluster-error" class="dply-card overflow-hidden border-rose-200">
                <div class="border-b border-brand-ink/10 bg-rose-50/70 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['rose'] }}">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Cluster unavailable') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ __(':provider can\'t find this cluster anymore', ['provider' => $kubernetesError['provider_label']]) }}
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $kubernetesError['message'] }}</p>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-5 sm:px-7">
                    <dl class="grid gap-3 text-xs sm:grid-cols-2">
                        @if ($kubernetesError['cluster_name'] !== '')
                            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cluster name') }}</dt>
                                <dd class="mt-1 font-mono text-brand-ink">{{ $kubernetesError['cluster_name'] }}</dd>
                            </div>
                        @endif
                        @if ($kubernetesError['cluster_id'] !== '')
                            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cluster id') }}</dt>
                                <dd class="mt-1 break-all font-mono text-brand-ink">{{ $kubernetesError['cluster_id'] }}</dd>
                            </div>
                        @endif
                    </dl>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="retryClusterPolling"
                            wire:loading.attr="disabled"
                            wire:target="retryClusterPolling"
                            class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-xl bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-arrow-path wire:loading.remove wire:target="retryClusterPolling" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <x-spinner wire:loading wire:target="retryClusterPolling" variant="white" size="sm" />
                            {{ __('Re-check now') }}
                        </button>
                        @feature('workspace.cluster')
                            <a href="{{ route('servers.cluster', $server) }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                {{ __('Open cluster page') }}
                            </a>
                        @endfeature
                        <a href="{{ $kubernetesError['provider_console_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            {{ __('Open in :provider', ['provider' => $kubernetesError['provider_label']]) }}
                            <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        </a>
                    </div>
                </div>
            </section>
        @endif

        {{-- Project context (feature-gated) --}}
        @if ($server->workspace)
            @feature('surface.projects')
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Project') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $server->workspace->name }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                {{ __('This server is managed as part of the project. Use the project pages when you need access control, grouped activity, shared variables, coordinated deploys, or cross-resource health review.') }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="{{ route('projects.overview', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                    <x-heroicon-m-eye class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Project overview') }}
                                </a>
                                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                    <x-heroicon-m-bolt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Project operations') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            @endfeature
        @endif

        @if ($setupIncomplete)
            {{-- Setup-in-progress hero — kept dark/theatrical because it's a
                 "stop everything" state and intentionally outweighs the chrome. --}}
            <section class="relative overflow-hidden rounded-3xl border border-brand-ink/10 bg-brand-ink px-6 py-7 text-brand-cream shadow-[0_30px_90px_rgba(19,28,23,0.18)] sm:px-8 sm:py-8">
                <div class="pointer-events-none absolute inset-0">
                    <div class="absolute inset-x-0 top-0 h-px bg-white/10"></div>
                    <div class="absolute -right-16 top-1/2 h-40 w-40 -translate-y-1/2 rounded-full bg-brand-sage/20 blur-3xl"></div>
                </div>

                <div class="relative max-w-4xl">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center gap-2 whitespace-nowrap rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-sand/90">
                            <span class="inline-flex h-2 w-2 rounded-full bg-amber-300 shadow-[0_0_0_4px_rgba(252,211,77,0.16)]"></span>
                            {{ __('Setup in progress') }}
                        </span>
                        <span class="inline-flex items-center whitespace-nowrap rounded-full border border-white/10 bg-black/10 px-3 py-1.5 text-xs font-medium text-brand-cream/80">
                            {{ __('Workspace unlocks after setup finishes') }}
                        </span>
                    </div>

                    <div class="mt-5">
                        <h2 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">
                            {{ __('Finish setup before using this server.') }}
                        </h2>
                        <p class="mt-3 max-w-3xl text-sm leading-relaxed text-brand-cream/80">
                            {{ __('Reconnect over SSH, watch live installation output, and re-run setup safely if this server needs another pass before the workspace is unlocked.') }}
                        </p>

                        <div class="mt-5 flex flex-wrap gap-2 text-xs text-brand-cream/75">
                            <span class="inline-flex items-center whitespace-nowrap rounded-md border border-white/10 bg-white/5 px-2.5 py-1">
                                {{ __('Provider') }}: <span class="ml-1.5 font-semibold text-white">{{ $server->provider->label() }}</span>
                            </span>
                            <span class="inline-flex items-center whitespace-nowrap rounded-md border border-white/10 bg-white/5 px-2.5 py-1">
                                {{ __('IP') }}: <span class="ml-1.5 font-mono font-semibold text-white">{{ $server->ip_address ?? '—' }}</span>
                            </span>
                            <span class="inline-flex items-center whitespace-nowrap rounded-md border border-white/10 bg-white/5 px-2.5 py-1">
                                {{ __('Setup') }}: <span class="ml-1.5 font-semibold text-white">{{ ucfirst($server->setup_status ?? __('Pending')) }}</span>
                            </span>
                        </div>

                        <div class="mt-6 max-w-3xl rounded-2xl border border-white/10 bg-white/95 p-5 text-brand-ink shadow-[0_20px_70px_rgba(12,18,15,0.16)]">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Next step') }}</p>
                            <p class="mt-1.5 text-base font-semibold tracking-tight text-brand-ink">{{ __('Open the setup journey') }}</p>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">
                                {{ __('Watch live progress, inspect current output, and re-run installation from a clean tracked setup task if needed.') }}
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a
                                    href="{{ route('servers.journey', $server) }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                                >
                                    <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Open setup journey') }}
                                </a>
                                @if (\App\Jobs\RunSetupScriptJob::shouldDispatch($server))
                                    <button
                                        type="button"
                                        wire:click="rerunSetup"
                                        wire:loading.attr="disabled"
                                        wire:target="rerunSetup"
                                        class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="rerunSetup" class="inline-flex items-center gap-2">
                                            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Re-run setup') }}
                                        </span>
                                        <span wire:loading wire:target="rerunSetup" class="inline-flex items-center gap-2 whitespace-nowrap">
                                            <x-spinner variant="ink" size="sm" />
                                            {{ __('Re-running…') }}
                                        </span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @else
            @php
                $healthStatus = $healthSummary['status'];
                $healthLabel = match ($healthStatus) {
                    \App\Models\Server::HEALTH_REACHABLE => __('Reachable'),
                    \App\Models\Server::HEALTH_UNREACHABLE => __('Needs attention'),
                    default => __('No health check yet'),
                };
                $healthDot = $healthStatus === \App\Models\Server::HEALTH_REACHABLE
                    ? 'bg-emerald-500'
                    : ($healthStatus === \App\Models\Server::HEALTH_UNREACHABLE ? 'bg-rose-500' : 'bg-brand-gold');
            @endphp

            {{-- Hero: server identity + facts. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-server-stack class="h-6 w-6" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Server') }}</p>
                                <h1 class="mt-1 truncate text-xl font-semibold tracking-tight text-brand-ink">{{ $server->name }}</h1>
                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-brand-ink/10 bg-white px-2 py-0.5">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $healthDot }}"></span>
                                        {{ $healthLabel }}
                                    </span>
                                    <span class="inline-flex items-center gap-1 font-mono">
                                        <span class="text-[10px] uppercase tracking-[0.16em] text-brand-mist">SSH</span>
                                        <span class="break-all text-brand-ink">{{ $server->getSshConnectionString() }}</span>
                                    </span>
                                    @if ($healthSummary['last_checked_at'])
                                        <span class="text-brand-mist" title="{{ __('Last health check') }}">
                                            {{ __('Checked :ago', ['ago' => $healthSummary['last_checked_at']->diffForHumans()]) }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    @php
                        $heroProvider = $server->provider->label();
                        $heroRegion = $server->region ?: '—';
                        $heroIp = $server->ip_address ?? '—';
                        $heroSize = $server->size ?? '—';
                    @endphp
                    <dl class="grid grid-cols-2 gap-2 lg:col-span-5 lg:grid-cols-4">
                        <x-tooltip :label="__('Provider').': '.$heroProvider" class="w-full">
                            <div class="w-full rounded-2xl border border-brand-ink/10 bg-white px-3 py-2.5 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Provider') }}</dt>
                                <dd class="mt-0.5 truncate text-xs font-semibold text-brand-ink">{{ $heroProvider }}</dd>
                            </div>
                        </x-tooltip>
                        <x-tooltip :label="__('Region').': '.$heroRegion" class="w-full">
                            <div class="w-full rounded-2xl border border-brand-ink/10 bg-white px-3 py-2.5 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Region') }}</dt>
                                <dd class="mt-0.5 truncate text-xs font-semibold text-brand-ink">{{ $heroRegion }}</dd>
                            </div>
                        </x-tooltip>
                        <x-tooltip :label="__('IP').': '.$heroIp" class="w-full">
                            <div class="w-full rounded-2xl border border-brand-ink/10 bg-white px-3 py-2.5 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('IP') }}</dt>
                                <dd class="mt-0.5 truncate font-mono text-xs font-semibold text-brand-ink">{{ $heroIp }}</dd>
                            </div>
                        </x-tooltip>
                        <x-tooltip :label="__('Size').': '.$heroSize" class="w-full">
                            <div class="w-full rounded-2xl border border-brand-ink/10 bg-white px-3 py-2.5 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</dt>
                                <dd class="mt-0.5 truncate font-mono text-xs font-semibold text-brand-ink">{{ $heroSize }}</dd>
                            </div>
                        </x-tooltip>
                    </dl>
                </div>
            </section>

            {{-- Onboarding checklist. --}}
            @if (! $onboardingComplete && $onboardingTotal > 0)
                @php $onboardingPct = max(0, min(100, (int) round(100 * $onboardingDone / $onboardingTotal))); @endphp
                <section
                    data-testid="server-onboarding-checklist"
                    x-data="{ open: @js($onboardingDone === 0) }"
                    class="dply-card overflow-hidden"
                >
                    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <button
                            type="button"
                            x-on:click="open = ! open"
                            class="flex w-full items-start gap-3 text-left"
                        >
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Get started') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                    {{ trans_choice('{1} :n step left to make this server useful|[2,*] :n steps left to make this server useful', $onboardingTotal - $onboardingDone, ['n' => $onboardingTotal - $onboardingDone]) }}
                                </h3>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <div class="hidden w-32 sm:block">
                                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/5">
                                        <div class="h-full rounded-full bg-sky-500 transition-[width] duration-500" style="width: {{ $onboardingPct }}%"></div>
                                    </div>
                                </div>
                                <span class="rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-sky-700 ring-1 ring-sky-200">{{ $onboardingDone }}/{{ $onboardingTotal }}</span>
                                <x-heroicon-m-chevron-down class="h-5 w-5 text-brand-moss transition-transform" x-bind:class="{ 'rotate-180': open }" />
                            </div>
                        </button>
                    </div>
                    <ul x-show="open" x-collapse x-cloak class="divide-y divide-brand-ink/10">
                        @foreach ($onboardingSteps as $step)
                            <li class="flex items-center justify-between gap-3 px-6 py-3 sm:px-7">
                                <div class="flex min-w-0 items-start gap-3">
                                    @if ($step['done'])
                                        <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">
                                            <x-heroicon-m-check class="h-3.5 w-3.5" />
                                        </span>
                                    @else
                                        <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-sky-300 bg-white"></span>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="text-sm {{ $step['done'] ? 'text-brand-moss line-through' : 'font-medium text-brand-ink' }}">{{ $step['label'] }}</p>
                                        @if (! $step['done'])
                                            <p class="mt-0.5 text-xs text-brand-moss">{{ $step['help'] }}</p>
                                        @endif
                                    </div>
                                </div>
                                @if (! $step['done'])
                                    <a href="{{ $step['cta_route'] }}" wire:navigate class="shrink-0 inline-flex items-center gap-1 whitespace-nowrap rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-sky-700">
                                        {{ $step['cta_label'] }}
                                        <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Live system metrics. --}}
            @php
                $metricPayload = is_object($latestMetricSnapshot) && is_array($latestMetricSnapshot->payload ?? null)
                    ? $latestMetricSnapshot->payload
                    : [];
                $metricCpu = isset($metricPayload['cpu_pct']) && is_numeric($metricPayload['cpu_pct']) ? (float) $metricPayload['cpu_pct'] : null;
                $metricMem = isset($metricPayload['mem_pct']) && is_numeric($metricPayload['mem_pct']) ? (float) $metricPayload['mem_pct'] : null;
                $metricDisk = isset($metricPayload['disk_pct']) && is_numeric($metricPayload['disk_pct']) ? (float) $metricPayload['disk_pct'] : null;
                $metricLoad1m = isset($metricPayload['load_1m']) && is_numeric($metricPayload['load_1m']) ? (float) $metricPayload['load_1m'] : null;
                $metricLoadPerCpu = isset($metricPayload['load_per_cpu_1m']) && is_numeric($metricPayload['load_per_cpu_1m']) ? (float) $metricPayload['load_per_cpu_1m'] : null;
                $metricHasAny = $metricCpu !== null || $metricMem !== null || $metricDisk !== null;
                $metricCapturedAt = is_object($latestMetricSnapshot) ? $latestMetricSnapshot->captured_at : null;
                $metricStale = $metricCapturedAt && $metricCapturedAt->lt(now()->subMinutes(10));
                $metricBar = function (?float $pct): array {
                    if ($pct === null) {
                        return ['width' => 0, 'color' => 'bg-brand-mist/40'];
                    }
                    $clamped = max(0.0, min(100.0, $pct));
                    if ($pct >= 95) {
                        $color = 'bg-rose-500';
                    } elseif ($pct >= 85) {
                        $color = 'bg-amber-500';
                    } else {
                        $color = 'bg-emerald-500';
                    }

                    return ['width' => $clamped, 'color' => $color];
                };
                $metricRow = function (string $label, ?float $pct) use ($metricBar): string {
                    $bar = $metricBar($pct);
                    $val = $pct === null ? '—' : number_format($pct, 0).'%';

                    return view('livewire.servers.partials._overview-metric-row', [
                        'label' => $label,
                        'value' => $val,
                        'barColor' => $bar['color'],
                        'barWidth' => $bar['width'],
                    ])->render();
                };
            @endphp
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Live load') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('System load right now') }}</h3>
                            @if ($metricCapturedAt)
                                <p class="mt-1 text-xs {{ $metricStale ? 'text-amber-700' : 'text-brand-mist' }}">
                                    {{ __('Sampled :ago', ['ago' => $metricCapturedAt->diffForHumans()]) }}
                                    @if ($metricStale)
                                        · <span class="font-semibold uppercase tracking-wide">{{ __('stale') }}</span>
                                    @endif
                                </p>
                            @endif
                        </div>
                        <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-m-chart-bar class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Open Monitor') }}
                        </a>
                    </div>
                </div>
                <div class="p-6 sm:p-7">
                    @if (! $metricHasAny)
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-4 py-3 text-sm text-brand-moss">
                            {{ __('No metric snapshots yet — install the monitor agent from the Monitor tab so this card lights up.') }}
                        </div>
                    @else
                        <div class="grid gap-3 sm:grid-cols-3">
                            {!! $metricRow(__('CPU'), $metricCpu) !!}
                            {!! $metricRow(__('Memory'), $metricMem) !!}
                            {!! $metricRow(__('Disk'), $metricDisk) !!}
                        </div>
                        @if ($metricLoad1m !== null)
                            <p class="mt-3 text-xs text-brand-moss">
                                {{ __('Load (1m)') }}:
                                <span class="font-mono font-semibold text-brand-ink">{{ number_format($metricLoad1m, 2) }}</span>
                                @if ($metricLoadPerCpu !== null)
                                    <span class="text-brand-mist"> · </span>
                                    {{ __('per CPU') }}:
                                    <span class="font-mono font-semibold text-brand-ink">{{ number_format($metricLoadPerCpu, 2) }}</span>
                                @endif
                            </p>
                        @endif
                    @endif
                </div>
            </section>

            {{-- SSH key reminder --}}
            @if (! $serverHasPersonalProfileKey)
                <section class="dply-card overflow-hidden border-amber-200">
                    <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                                    <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Access') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add your personal SSH key before you need this server') }}</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                        @if ($hasProfileSshKeys)
                                            {{ __('This server is ready, but it does not yet include one of your personal profile SSH keys. Attach one from the SSH keys workspace and sync authorized_keys so your own login access is on the machine.') }}
                                        @else
                                            {{ __('This server is ready, but you do not have any personal SSH keys saved in your profile yet. Add one first, then attach it from the SSH keys workspace so your own login access is on the machine.') }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2">
                                @if (! $hasProfileSshKeys)
                                    <a href="{{ route('profile.ssh-keys') }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                                        <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Add a profile key') }}
                                    </a>
                                @endif
                                <a href="{{ route('servers.ssh-keys', $server) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                    {{ __('Open SSH keys workspace') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Container launch progress partial. --}}
            @include('livewire.servers.partials._container-launch-progress')

            {{-- First container app CTA. --}}
            @if ($isContainerHost && $siteCount === 0 && ! $containerLaunch)
                <section data-testid="add-first-container-cta" class="dply-card overflow-hidden">
                    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                    <x-heroicon-o-cube-transparent class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Next step') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add your first container app') }}</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Point dply at a Git repo and we will inspect the Dockerfile, build the image, and deploy onto this host. You can add more apps any time.') }}</p>
                                </div>
                            </div>
                            <a href="{{ route('sites.create', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-xl bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-sky-700">
                                <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Add container app') }}
                            </a>
                        </div>
                    </div>
                </section>
            @endif

            {{-- 5 click-through stat tiles, wrapped in a family section card. --}}
            @php
                $healthValue = match ($healthSummary['status']) {
                    \App\Models\Server::HEALTH_REACHABLE => __('Reachable'),
                    \App\Models\Server::HEALTH_UNREACHABLE => __('Unreachable'),
                    default => __('Not checked yet'),
                };
                $healthMeta = $healthSummary['last_checked_at']
                    ? __('Last checked :time', ['time' => $healthSummary['last_checked_at']->diffForHumans()])
                    : __('No checks yet');
                $deployingMeta = $deployingCount > 0
                    ? trans_choice('{1} :count site deploying|[2,*] :count sites deploying', $deployingCount, ['count' => $deployingCount])
                    : trans_choice('{0} No sites yet|{1} 1 site|[2,*] :count sites', $siteCount, ['count' => $siteCount]);
                $latestDeployValue = $latestDeployment?->status
                    ? str($latestDeployment->status)->headline()
                    : __('None yet');
                $latestDeployMeta = $latestDeployment?->site
                    ? __(':site · :time', [
                        'site' => $latestDeployment->site->name,
                        'time' => ($latestDeployment->finished_at ?? $latestDeployment->created_at)?->diffForHumans() ?? __('just now'),
                    ])
                    : __('No deploys yet');
            @endphp
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-squares-2x2 class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('At a glance') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Workspace summary') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Each tile drops you onto its full workspace page.') }}</p>
                        </div>
                    </div>
                </div>
                <div class="grid gap-3 p-6 sm:grid-cols-2 sm:p-7 lg:grid-cols-3 xl:grid-cols-5">
                    <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Health') }}</p>
                        <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $healthValue }}</p>
                        <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $healthMeta }}</p>
                        <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                            {{ __('Open Monitor') }}
                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                        </p>
                    </a>

                    <a href="{{ route('servers.sites', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Sites') }}</p>
                        <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $siteCount }}</p>
                        <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $deployingMeta }}</p>
                        <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                            {{ __('Open Sites') }}
                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                        </p>
                    </a>

                    <a href="{{ route('servers.databases', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Databases') }}</p>
                        <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $databaseSummary['count'] }}</p>
                        <p class="mt-0.5 truncate text-[11px] text-brand-moss">
                            @if ($installedStack->database)
                                {{ str($installedStack->database)->headline() }}@if ($installedStack->databaseVersion) · {{ $installedStack->databaseVersion }}@endif
                            @else
                                {{ __('No engine recorded') }}
                            @endif
                        </p>
                        <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                            {{ __('Open Databases') }}
                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                        </p>
                    </a>

                    <a href="{{ route('servers.deploys', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Latest deploy') }}</p>
                        <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $latestDeployValue }}</p>
                        <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $latestDeployMeta }}</p>
                        <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                            {{ __('Open Deploys') }}
                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                        </p>
                    </a>

                    <a href="{{ route('servers.backups', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Background') }}</p>
                        <p class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $backgroundSummary['active_workers'] }}</span>
                            <span class="text-[11px] text-brand-moss">{{ __('workers') }}</span>
                        </p>
                        <p class="mt-0.5 truncate text-[11px]">
                            @if ($backgroundSummary['failed_backups_7d'] > 0)
                                <span class="font-semibold text-red-700">{{ trans_choice('{1} :count failed backup (7d)|[2,*] :count failed backups (7d)', $backgroundSummary['failed_backups_7d'], ['count' => $backgroundSummary['failed_backups_7d']]) }}</span>
                            @elseif ($backgroundSummary['paused_schedules'] > 0)
                                <span class="text-amber-700">{{ trans_choice('{1} :count paused schedule|[2,*] :count paused schedules', $backgroundSummary['paused_schedules'], ['count' => $backgroundSummary['paused_schedules']]) }}</span>
                            @elseif ($backgroundSummary['active_schedules'] > 0)
                                <span class="text-brand-moss">{{ trans_choice('{1} :count active schedule|[2,*] :count active schedules', $backgroundSummary['active_schedules'], ['count' => $backgroundSummary['active_schedules']]) }}</span>
                            @else
                                <span class="text-brand-moss">{{ __('No schedules yet') }}</span>
                            @endif
                        </p>
                        <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                            {{ __('Open Backups') }}
                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                        </p>
                    </a>
                </div>
            </section>

            {{-- Sites preview. --}}
            @if ($sitesPreview->isNotEmpty())
                <section class="dply-card overflow-hidden">
                    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Sites') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent activity') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Top sites by last-touched, with each site\'s most recent deploy.') }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($siteCount > 0)
                                    <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $siteCount }}</span>
                                @endif
                                <a href="{{ route('servers.sites', $server) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                    <x-heroicon-m-rectangle-stack class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Open Sites') }}
                                </a>
                            </div>
                        </div>
                    </div>
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($sitesPreview as $previewSite)
                            @php
                                $deploy = $sitesPreviewLatestDeploys[$previewSite->id] ?? null;
                                $deployStatus = $deploy?->status ? (string) $deploy->status : null;
                                $deployTime = $deploy ? ($deploy->finished_at ?? $deploy->created_at) : null;
                                $statusBadge = match ($previewSite->status) {
                                    'active', 'ready' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                    'deploying', 'queued' => 'border-sky-200 bg-sky-50 text-sky-700',
                                    'failed', 'error' => 'border-rose-200 bg-rose-50 text-rose-700',
                                    default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
                                };
                            @endphp
                            <li wire:key="site-preview-{{ $previewSite->id }}" class="flex items-center justify-between gap-3 px-6 py-3 transition-colors hover:bg-brand-sand/15 sm:px-7">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <a href="{{ route('sites.show', ['server' => $server, 'site' => $previewSite]) }}" wire:navigate class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">
                                            {{ $previewSite->name }}
                                        </a>
                                        <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusBadge }}">
                                            {{ $previewSite->status ?? '—' }}
                                        </span>
                                    </div>
                                    @if ($deployTime)
                                        <p class="mt-0.5 text-[11px] text-brand-mist">
                                            {{ __('Last deploy :time', ['time' => $deployTime->diffForHumans()]) }}
                                            @if ($deployStatus)
                                                <span class="text-brand-mist/60"> · </span>
                                                <span class="text-brand-moss">{{ str($deployStatus)->headline() }}</span>
                                            @endif
                                        </p>
                                    @else
                                        <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('No deploys yet') }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($siteCount > $sitesPreview->count())
                        <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 text-xs text-brand-mist sm:px-7">
                            {{ __('Showing :n of :total — open Sites to see the rest.', ['n' => $sitesPreview->count(), 'total' => $siteCount]) }}
                        </div>
                    @endif
                </section>
            @endif

            {{-- Stack summary --}}
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Stack') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Installed runtime') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Database engine, language runtime, webserver, cache.') }}</p>
                        </div>
                        @feature('workspace.services')
                            <a href="{{ route('servers.services', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                <x-heroicon-m-cpu-chip class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Open Services') }}
                            </a>
                        @endfeature
                    </div>
                </div>
                <div class="p-6 sm:p-7">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        @if ($installedStack->database)
                            <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-1 font-medium text-brand-ink">
                                {{ str($installedStack->database)->headline() }}@if ($installedStack->databaseVersion)<span class="ml-1 font-mono text-xs text-brand-moss">{{ $installedStack->databaseVersion }}</span>@endif
                            </span>
                        @endif
                        @if ($installedStack->phpVersion)
                            <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-1 font-medium text-brand-ink">
                                PHP <span class="ml-1 font-mono text-xs text-brand-moss">{{ $installedStack->phpVersion }}</span>
                            </span>
                        @endif
                        @if ($installedStack->webserver)
                            <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-1 font-medium text-brand-ink">
                                {{ str($installedStack->webserver)->headline() }}
                            </span>
                        @endif
                        @if ($installedStack->cacheService && $installedStack->cacheService !== 'none')
                            <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-1 font-medium text-brand-ink">
                                {{ str($installedStack->cacheService)->headline() }}
                            </span>
                        @endif
                        @if ($installedStack->lowMemoryMode)
                            <span class="inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800" title="{{ __('Provisioned in low-memory mode — substituted lighter services where possible.') }}">
                                <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Low-memory mode') }}
                            </span>
                        @endif
                    </div>
                    @if ($installedStackDiverges)
                        <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-2 text-xs leading-relaxed text-amber-900">
                            {{ __('Wizard requested :requested but :installed was installed instead. See journey for context.', [
                                'requested' => $server->meta['database'] ?? '—',
                                'installed' => $installedStack->database ?? '—',
                            ]) }}
                        </p>
                    @endif
                </div>
            </section>

            {{-- Health cockpit shortcut (VM + flag). --}}
            @feature('workspace.health')
            @if ($healthCockpitSummary)
                @php
                    $healthCritical = $healthCockpitSummary['overall'] === 'critical';
                    $healthWarning = $healthCockpitSummary['overall'] === 'warning';
                @endphp
                <section @class([
                    'dply-card overflow-hidden',
                    'border-rose-200' => $healthCritical,
                    'border-amber-200' => $healthWarning && ! $healthCritical,
                ])>
                    <div @class([
                        'border-b border-brand-ink/10 px-6 py-5 sm:px-7',
                        'bg-rose-50/60' => $healthCritical,
                        'bg-amber-50/60' => $healthWarning && ! $healthCritical,
                        'bg-brand-sand/20' => ! $healthCritical && ! $healthWarning,
                    ])>
                        <div class="flex items-start gap-3">
                            <span @class([
                                'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1',
                                'bg-rose-50 text-rose-700 ring-rose-200' => $healthCritical,
                                'bg-amber-100 text-amber-700 ring-amber-200' => $healthWarning && ! $healthCritical,
                                'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $healthCritical && ! $healthWarning,
                            ])>
                                <x-heroicon-o-heart class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p @class([
                                    'text-[11px] font-semibold uppercase tracking-[0.16em]',
                                    'text-rose-700' => $healthCritical,
                                    'text-amber-800' => $healthWarning && ! $healthCritical,
                                    'text-brand-sage' => ! $healthCritical && ! $healthWarning,
                                ])>{{ __('Health cockpit') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                    @if ($healthCockpitSummary['alert_count'] > 0)
                                        {{ trans_choice(':count open alert|:count open alerts', $healthCockpitSummary['alert_count'], ['count' => $healthCockpitSummary['alert_count']]) }}
                                    @else
                                        {{ __('No open alerts') }}
                                    @endif
                                </h3>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Capacity, releases, deploys, certificates, and daemons in one view.') }}</p>
                            </div>
                            <a href="{{ route('servers.health', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                {{ __('Open Health') }}
                                <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </a>
                        </div>
                    </div>
                </section>
            @endif
            @endfeature

            {{-- Cost card shortcut (VM + flag). --}}
            @feature('workspace.server_cost')
            @if ($costCardSummary)
                @php
                    $costNudgeWarning = ($costCardSummary['nudge_severity'] ?? null) === 'warning';
                @endphp
                <section @class([
                    'dply-card overflow-hidden',
                    'border-amber-200' => $costNudgeWarning,
                ])>
                    <div @class([
                        'border-b border-brand-ink/10 px-6 py-5 sm:px-7',
                        'bg-amber-50/60' => $costNudgeWarning,
                        'bg-brand-sand/20' => ! $costNudgeWarning,
                    ])>
                        <div class="flex items-start gap-3">
                            <span @class([
                                'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1',
                                'bg-amber-100 text-amber-700 ring-amber-200' => $costNudgeWarning,
                                'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $costNudgeWarning,
                            ])>
                                <x-heroicon-o-currency-dollar class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p @class([
                                    'text-[11px] font-semibold uppercase tracking-[0.16em]',
                                    'text-amber-800' => $costNudgeWarning,
                                    'text-brand-sage' => ! $costNudgeWarning,
                                ])>{{ __('Cost card') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $costCardSummary['formatted_total'] }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    @if ($costCardSummary['nudge_title'])
                                        {{ $costCardSummary['nudge_title'] }}
                                    @else
                                        {{ __('Provider estimate + dply tier fee for this server.') }}
                                    @endif
                                </p>
                            </div>
                            <a href="{{ route('servers.settings', ['server' => $server, 'section' => 'governance']) }}#settings-cost-estimate" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                {{ __('Open Cost') }}
                                <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </a>
                        </div>
                    </div>
                </section>
            @endif
            @endfeature

            {{-- Patch advisor shortcut (VM + flag). --}}
            @feature('workspace.patch_advisor')
            @if ($patchAdvisorSummary && ($patchAdvisorSummary['alert_count'] > 0 || $patchAdvisorSummary['reboot_required'] === true))
                @php
                    $patchCritical = $patchAdvisorSummary['overall'] === 'critical';
                    $patchWarning = $patchAdvisorSummary['overall'] === 'warning';
                @endphp
                <section @class([
                    'dply-card overflow-hidden',
                    'border-rose-200' => $patchCritical,
                    'border-amber-200' => $patchWarning && ! $patchCritical,
                ])>
                    <div @class([
                        'border-b border-brand-ink/10 px-6 py-5 sm:px-7',
                        'bg-rose-50/60' => $patchCritical,
                        'bg-amber-50/60' => $patchWarning && ! $patchCritical,
                        'bg-brand-sand/20' => ! $patchCritical && ! $patchWarning,
                    ])>
                        <div class="flex items-start gap-3">
                            <span @class([
                                'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1',
                                'bg-rose-50 text-rose-700 ring-rose-200' => $patchCritical,
                                'bg-amber-100 text-amber-700 ring-amber-200' => $patchWarning && ! $patchCritical,
                                'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $patchCritical && ! $patchWarning,
                            ])>
                                <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p @class([
                                    'text-[11px] font-semibold uppercase tracking-[0.16em]',
                                    'text-rose-700' => $patchCritical,
                                    'text-amber-800' => $patchWarning && ! $patchCritical,
                                    'text-brand-sage' => ! $patchCritical && ! $patchWarning,
                                ])>{{ __('Patch advisor') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                    @if ($patchAdvisorSummary['reboot_required'] === true)
                                        {{ __('Reboot required') }}
                                    @elseif ($patchAdvisorSummary['security'] > 0)
                                        {{ trans_choice(':count security update|:count security updates', $patchAdvisorSummary['security'], ['count' => $patchAdvisorSummary['security']]) }}
                                    @elseif ($patchAdvisorSummary['alert_count'] > 0)
                                        {{ trans_choice(':count patch alert|:count patch alerts', $patchAdvisorSummary['alert_count'], ['count' => $patchAdvisorSummary['alert_count']]) }}
                                    @else
                                        {{ __('Review pending updates') }}
                                    @endif
                                </h3>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('apt updates, reboot flags, and uptime from the inventory probe.') }}</p>
                            </div>
                            <a href="{{ route('servers.patches', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                {{ __('Open Patches') }}
                                <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </a>
                        </div>
                    </div>
                </section>
            @endif
            @endfeature

            {{-- Release hygiene shortcut (VM + flag). --}}
            @feature('workspace.release_hygiene')
            @if ($releaseHygieneSummary && $releaseHygieneSummary['alert_count'] > 0)
                @php
                    $hygieneCritical = $releaseHygieneSummary['overall'] === 'critical';
                    $hygieneWarning = $releaseHygieneSummary['overall'] === 'warning';
                @endphp
                <section @class([
                    'dply-card overflow-hidden',
                    'border-rose-200' => $hygieneCritical,
                    'border-amber-200' => $hygieneWarning && ! $hygieneCritical,
                ])>
                    <div @class([
                        'border-b border-brand-ink/10 px-6 py-5 sm:px-7',
                        'bg-rose-50/60' => $hygieneCritical,
                        'bg-amber-50/60' => $hygieneWarning && ! $hygieneCritical,
                        'bg-brand-sand/20' => ! $hygieneCritical && ! $hygieneWarning,
                    ])>
                        <div class="flex items-start gap-3">
                            <span @class([
                                'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1',
                                'bg-rose-50 text-rose-700 ring-rose-200' => $hygieneCritical,
                                'bg-amber-100 text-amber-700 ring-amber-200' => $hygieneWarning && ! $hygieneCritical,
                                'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $hygieneCritical && ! $hygieneWarning,
                            ])>
                                <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p @class([
                                    'text-[11px] font-semibold uppercase tracking-[0.16em]',
                                    'text-rose-700' => $hygieneCritical,
                                    'text-amber-800' => $hygieneWarning && ! $hygieneCritical,
                                    'text-brand-sage' => ! $hygieneCritical && ! $hygieneWarning,
                                ])>{{ __('Release hygiene') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                    {{ trans_choice(':count cleanup alert|:count cleanup alerts', $releaseHygieneSummary['alert_count'], ['count' => $releaseHygieneSummary['alert_count']]) }}
                                </h3>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Release folders, Laravel logs, and failed queue jobs on this server.') }}</p>
                            </div>
                            <a href="{{ route('servers.hygiene', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                {{ __('Open Hygiene') }}
                                <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </a>
                        </div>
                    </div>
                </section>
            @endif
            @endfeature

            @if ($sharedHostSummary)
                @php
                    $sharedHostCritical = ($sharedHostSummary['severity'] ?? '') === 'critical';
                    $sharedHostWarning = ($sharedHostSummary['severity'] ?? '') === 'warning';
                    $sharedHostPreview = (bool) ($sharedHostSummary['preview'] ?? false);
                @endphp
                <section @class([
                    'dply-card overflow-hidden',
                    'border-rose-200' => $sharedHostCritical,
                    'border-amber-200' => $sharedHostWarning && ! $sharedHostCritical,
                    'border-sky-200' => $sharedHostPreview,
                ])>
                    <div @class([
                        'border-b border-brand-ink/10 px-6 py-5 sm:px-7',
                        'bg-rose-50/60' => $sharedHostCritical,
                        'bg-amber-50/60' => $sharedHostWarning && ! $sharedHostCritical,
                        'bg-sky-50/60' => $sharedHostPreview,
                        'bg-brand-sand/20' => ! $sharedHostCritical && ! $sharedHostWarning && ! $sharedHostPreview,
                    ])>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <span @class([
                                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1',
                                    'bg-rose-50 text-rose-700 ring-rose-200' => $sharedHostCritical,
                                    'bg-amber-100 text-amber-700 ring-amber-200' => $sharedHostWarning && ! $sharedHostCritical,
                                    'bg-sky-100 text-sky-800 ring-sky-200' => $sharedHostPreview,
                                    'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $sharedHostCritical && ! $sharedHostWarning && ! $sharedHostPreview,
                                ])>
                                    <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p @class([
                                        'text-[11px] font-semibold uppercase tracking-[0.16em]',
                                        'text-rose-700' => $sharedHostCritical,
                                        'text-amber-800' => $sharedHostWarning && ! $sharedHostCritical,
                                        'text-sky-800' => $sharedHostPreview,
                                        'text-brand-sage' => ! $sharedHostCritical && ! $sharedHostWarning && ! $sharedHostPreview,
                                    ])>{{ __('Shared Host Radar') }}@if ($sharedHostPreview) · {{ __('Soon') }}@endif</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $sharedHostSummary['title'] }}</h3>
                                    <p class="mt-1 text-sm text-brand-moss">{{ $sharedHostSummary['message'] }}</p>
                                </div>
                            </div>
                            <a href="{{ route('servers.shared-host', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                {{ $sharedHostPreview ? __('Preview radar') : __('Open radar') }}
                                <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </a>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Insights (conditional + flag-gated). --}}
            @feature('workspace.insights')
            @if ($openInsightsCount > 0)
                @php $insightsCritical = $criticalInsightsCount > 0; @endphp
                <section class="dply-card overflow-hidden {{ $insightsCritical ? 'border-red-200' : 'border-amber-200' }}">
                    <div class="border-b border-brand-ink/10 {{ $insightsCritical ? 'bg-red-50/60' : 'bg-amber-50/60' }} px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1 {{ $insightsCritical ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-amber-100 text-amber-700 ring-amber-200' }}">
                                <x-heroicon-o-light-bulb class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] {{ $insightsCritical ? 'text-red-700' : 'text-amber-800' }}">{{ __('Insights') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                    {{ trans_choice('{1} :count open finding|[2,*] :count open findings', $openInsightsCount, ['count' => $openInsightsCount]) }}
                                </h3>
                                @if ($insightsCritical)
                                    <p class="mt-1">
                                        <span class="inline-flex items-center gap-1 rounded-md border border-red-200 bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700">
                                            <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ trans_choice('{1} :count critical|[2,*] :count critical', $criticalInsightsCount, ['count' => $criticalInsightsCount]) }}
                                        </span>
                                    </p>
                                @endif
                            </div>
                            <a href="{{ route('servers.insights', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold {{ $insightsCritical ? 'text-red-700 hover:text-red-900' : 'text-amber-800 hover:text-amber-900' }} shadow-sm transition hover:bg-brand-sand/40">
                                {{ __('Open Insights') }}
                                <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </a>
                        </div>
                    </div>
                </section>
            @endif
            @endfeature

            {{-- Notifications --}}
            @if ($notificationSummary['manage_url'])
                <section class="dply-card overflow-hidden">
                    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Notifications') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                    @if ($notificationSummary['channel_count'] > 0)
                                        {{ trans_choice('{1} :count channel routing this server|[2,*] :count channels routing this server', $notificationSummary['channel_count'], ['count' => $notificationSummary['channel_count']]) }}
                                    @else
                                        {{ __('No channels routing yet') }}
                                    @endif
                                </h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                    @if ($notificationSummary['channel_count'] === 0)
                                        {{ __('Add a channel to get pinged when something matters on this box.') }}
                                    @else
                                        {{ __('Channels deliver alerts when health checks fail, deploys break, or schedules trip.') }}
                                    @endif
                                </p>
                            </div>
                            <a href="{{ $notificationSummary['manage_url'] }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                <x-heroicon-m-cog-6-tooth class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Manage') }}
                            </a>
                        </div>
                    </div>
                </section>
            @endif
        @endif

        {{-- Danger zone --}}
        @if (! $setupIncomplete)
            @can('delete', $server)
                <section class="dply-card overflow-hidden border-rose-200">
                    <div class="border-b border-rose-200 bg-rose-50/60 px-6 py-5 sm:px-7">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Danger zone') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Remove this server') }}</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                        {{ __('Deletes the dply server record, runs any provider teardown, and detaches sites / databases / backups. You\'ll be asked to type the server name to confirm and can schedule removal for a future date (runs at the end of that day in your app timezone).') }}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                wire:click="openRemoveServerModal"
                                class="inline-flex shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-red-700"
                            >
                                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Remove or schedule removal') }}
                            </button>
                        </div>
                    </div>
                </section>
            @endcan
        @endif
    </div>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
