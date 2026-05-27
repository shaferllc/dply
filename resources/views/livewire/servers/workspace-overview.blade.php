@php
    $card = 'dply-card overflow-hidden';
    // Container hosts (docker/kubernetes) don't run the VM-shaped setup journey,
    // so they're "setup complete" the moment they're STATUS_READY — even when
    // setup_status is null. Without this branch, Docker / K8s hosts show the
    // VM-shaped "setup in progress" hero instead of the at-a-glance overview
    // (and the new "Add your first container app" CTA below).
    $isContainerHost = in_array($server->hostKind(), [\App\Models\Server::HOST_KIND_DOCKER, \App\Models\Server::HOST_KIND_KUBERNETES], true);
    // Only VM hosts run the setup journey. Container, serverless (DO
    // Functions), and other non-VM hosts have no setup script — their
    // setup_status stays null — so they are complete the moment they are
    // STATUS_READY. Gating on isVmHost() instead of an explicit host-kind
    // list keeps every non-VM host out of the VM-shaped "setup in progress"
    // hero.
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
    @if ($provisionError && $server->status === \App\Models\Server::STATUS_ERROR)
        <div class="mx-auto mt-4 max-w-7xl px-4 sm:px-6 lg:px-8" data-testid="server-provision-error">
            <div class="rounded-2xl border-2 border-brand-rust/40 bg-brand-rust/5 p-4">
                <div class="flex items-start gap-3">
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-rust text-white">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-brand-rust">
                            {{ __('Provisioning failed at :provider', ['provider' => $provisionError['provider'] ?? 'the provider']) }}
                        </p>
                        <p class="mt-1 text-sm text-brand-ink">{{ $provisionError['message'] ?? __('Unknown error.') }}</p>
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
        </div>
    @endif

    {{-- K8s host whose cluster has gone away at the provider (deleted in the
         DO/AWS console, or the poller gave up after :tries attempts). Stays
         prominent at the top of the overview so the operator can't miss it —
         the rest of the overview's tiles read off a missing cluster and would
         otherwise look like a healthy-but-empty server. --}}
    @if (! empty($kubernetesError))
        <section data-testid="kubernetes-cluster-error" class="overflow-hidden rounded-[2rem] border border-rose-200 bg-rose-50 p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex min-w-0 flex-1 items-start gap-3">
                    <x-heroicon-o-exclamation-triangle class="mt-1 h-6 w-6 shrink-0 text-rose-600" />
                    <div class="min-w-0 space-y-3">
                        <span class="inline-flex items-center gap-2 rounded-full border border-rose-300 bg-white px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-rose-700">
                            <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                            {{ __('Cluster unavailable') }}
                        </span>
                        <h3 class="text-2xl font-semibold tracking-tight text-rose-900">
                            {{ __(':provider can\'t find this cluster anymore', ['provider' => $kubernetesError['provider_label']]) }}
                        </h3>
                        <p class="text-sm leading-6 text-rose-800">
                            {{ $kubernetesError['message'] }}
                        </p>
                        <dl class="grid gap-3 text-xs text-rose-900 sm:grid-cols-2">
                            @if ($kubernetesError['cluster_name'] !== '')
                                <div>
                                    <dt class="font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Cluster name') }}</dt>
                                    <dd class="mt-1 font-mono">{{ $kubernetesError['cluster_name'] }}</dd>
                                </div>
                            @endif
                            @if ($kubernetesError['cluster_id'] !== '')
                                <div>
                                    <dt class="font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Cluster id') }}</dt>
                                    <dd class="mt-1 break-all font-mono">{{ $kubernetesError['cluster_id'] }}</dd>
                                </div>
                            @endif
                        </dl>
                        <div class="flex flex-wrap gap-2 pt-2">
                            <button
                                type="button"
                                wire:click="retryClusterPolling"
                                wire:loading.attr="disabled"
                                wire:target="retryClusterPolling"
                                class="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg bg-rose-600 px-3 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60"
                            >
                                <x-heroicon-o-arrow-path wire:loading.remove wire:target="retryClusterPolling" class="h-4 w-4" />
                                <x-spinner wire:loading wire:target="retryClusterPolling" variant="white" size="sm" />
                                {{ __('Re-check now') }}
                            </button>
                            <a href="{{ route('servers.cluster', $server) }}" wire:navigate class="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 text-xs font-semibold text-rose-800 transition-colors hover:bg-rose-100">
                                {{ __('Open cluster page') }}
                            </a>
                            <a href="{{ $kubernetesError['provider_console_url'] }}" target="_blank" rel="noopener" class="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 text-xs font-semibold text-rose-800 transition-colors hover:bg-rose-100">
                                {{ __('Open in :provider', ['provider' => $kubernetesError['provider_label']]) }}
                                <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" />
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if ($server->workspace)
        @feature('surface.projects')
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
                <p class="font-semibold">{{ __('Project context') }}</p>
                <p class="mt-1 leading-relaxed text-brand-moss">
                    {{ __('This server is managed as part of the :project project. Use the project pages when you need access control, grouped activity, shared variables, coordinated deploys, or cross-resource health review.', ['project' => $server->workspace->name]) }}
                </p>
                <div class="mt-3 flex flex-wrap gap-3">
                    <a href="{{ route('projects.overview', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project overview') }}</a>
                    <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project operations') }}</a>
                </div>
            </div>
        @endfeature
    @endif

    <div class="{{ $card }} p-6 sm:p-8">
        @if ($setupIncomplete)
            {{-- Setup-in-progress hero — unchanged from the previous design.
                 Renders only while server.setup_status hasn't reached DONE.
                 Its job is to push the operator to the journey page; nothing
                 else on this view matters until setup completes. --}}
            <section class="relative overflow-hidden rounded-[2rem] border border-brand-ink/10 bg-brand-ink px-6 py-7 text-brand-cream shadow-[0_30px_90px_rgba(19,28,23,0.18)] sm:px-8 sm:py-8">
                <div class="pointer-events-none absolute inset-0">
                    <div class="absolute inset-x-0 top-0 h-px bg-white/10"></div>
                    <div class="absolute -right-16 top-1/2 h-40 w-40 -translate-y-1/2 rounded-full bg-brand-sage/20 blur-3xl"></div>
                </div>

                <div class="relative max-w-4xl">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.24em] text-brand-sand/90">
                            <span class="inline-flex h-2 w-2 rounded-full bg-amber-300 shadow-[0_0_0_4px_rgba(252,211,77,0.16)]"></span>
                            {{ __('Setup in progress') }}
                        </span>
                        <span class="inline-flex items-center rounded-full border border-white/10 bg-black/10 px-3 py-1.5 text-xs font-medium text-brand-cream/80">
                            {{ __('Workspace unlocks after setup finishes') }}
                        </span>
                    </div>

                    <div class="mt-6">
                        <h2 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl sm:leading-tight">
                            {{ __('Finish setup before using this server.') }}
                        </h2>
                        <p class="mt-3 max-w-3xl text-base leading-7 text-brand-cream/78">
                            {{ __('Reconnect over SSH, watch live installation output, and re-run setup safely if this server needs another pass before the workspace is unlocked.') }}
                        </p>

                        <div class="mt-6 flex flex-wrap gap-3 text-sm text-brand-cream/75">
                            <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-2">
                                {{ __('Provider') }}: <span class="ml-2 font-semibold text-white">{{ $server->provider->label() }}</span>
                            </span>
                            <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-2">
                                {{ __('IP') }}: <span class="ml-2 font-mono font-semibold text-white">{{ $server->ip_address ?? '—' }}</span>
                            </span>
                            <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-2">
                                {{ __('Setup') }}: <span class="ml-2 font-semibold text-white">{{ ucfirst($server->setup_status ?? __('Pending')) }}</span>
                            </span>
                        </div>

                        <div class="mt-12 max-w-3xl rounded-[1.5rem] border border-white/10 bg-white/95 p-5 text-brand-ink shadow-[0_20px_70px_rgba(12,18,15,0.16)]">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Next step') }}</p>
                            <p class="mt-2 text-lg font-semibold tracking-tight text-brand-ink">{{ __('Open the setup journey') }}</p>
                            <p class="mt-2 text-sm leading-6 text-brand-moss">
                                {{ __('Watch live progress, inspect current output, and re-run installation from a clean tracked setup task if needed.') }}
                            </p>
                            <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                                <a
                                    href="{{ route('servers.journey', $server) }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-3 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest sm:min-w-56"
                                >
                                    <x-heroicon-o-wrench-screwdriver class="h-4 w-4" />
                                    {{ __('Open setup journey') }}
                                </a>
                                @if (\App\Jobs\RunSetupScriptJob::shouldDispatch($server))
                                    <button
                                        type="button"
                                        wire:click="rerunSetup"
                                        wire:loading.attr="disabled"
                                        wire:target="rerunSetup"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-sand/60 bg-brand-sand/20 px-4 py-3 text-sm font-semibold text-brand-ink transition hover:border-brand-sage hover:bg-brand-sand/35 hover:text-brand-sage sm:min-w-48"
                                    >
                                        <span wire:loading.remove wire:target="rerunSetup" class="inline-flex items-center gap-2">
                                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                                            {{ __('Re-run setup') }}
                                        </span>
                                        <span wire:loading wire:target="rerunSetup" class="inline-flex items-center gap-2">
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
            {{-- Compact identity strip — name + SSH on the left, the chip rail
                 on the right. Was a generous two-column hero with a 24-line
                 H2 + SSH block; collapsed to a single row at lg+ so the
                 actual overview content (live metrics, tiles, sites preview)
                 sits above the fold. --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-5 py-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0 space-y-1.5">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h2 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ $server->name }}</h2>
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink">
                                <span class="h-1.5 w-1.5 rounded-full {{ $healthSummary['status'] === \App\Models\Server::HEALTH_REACHABLE ? 'bg-emerald-500' : ($healthSummary['status'] === \App\Models\Server::HEALTH_UNREACHABLE ? 'bg-rose-500' : 'bg-brand-gold') }}"></span>
                                {{ $healthSummary['status'] === \App\Models\Server::HEALTH_REACHABLE ? __('Reachable') : ($healthSummary['status'] === \App\Models\Server::HEALTH_UNREACHABLE ? __('Needs attention') : __('No health check yet')) }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-brand-moss">
                            <span class="inline-flex items-center gap-1.5 font-mono">
                                <span class="text-[10px] uppercase tracking-[0.18em] text-brand-mist">SSH</span>
                                <span class="break-all text-brand-ink">{{ $server->getSshConnectionString() }}</span>
                            </span>
                            @if ($server->setup_script_key)
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="text-[10px] uppercase tracking-[0.18em] text-brand-mist">{{ __('Setup') }}</span>
                                    <span class="text-brand-ink">{{ config("setup_scripts.scripts.{$server->setup_script_key}.name", $server->setup_script_key) }}</span>
                                </span>
                            @endif
                            @if ($healthSummary['last_checked_at'])
                                <span class="text-brand-mist" title="{{ __('Last health check') }}">
                                    {{ __('Checked :ago', ['ago' => $healthSummary['last_checked_at']->diffForHumans()]) }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-xs">
                        <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-brand-moss">
                            <span class="text-[10px] uppercase tracking-[0.16em] text-brand-mist">{{ __('Provider') }}</span>
                            <span class="ml-1.5 font-semibold text-brand-ink">{{ $server->provider->label() }}</span>
                        </span>
                        @if ($server->region)
                            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-brand-moss">
                                <span class="text-[10px] uppercase tracking-[0.16em] text-brand-mist">{{ __('Region') }}</span>
                                <span class="ml-1.5 font-semibold text-brand-ink">{{ $server->region }}</span>
                            </span>
                        @endif
                        <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-brand-moss">
                            <span class="text-[10px] uppercase tracking-[0.16em] text-brand-mist">{{ __('IP') }}</span>
                            <span class="ml-1.5 font-mono font-semibold text-brand-ink">{{ $server->ip_address ?? '—' }}</span>
                        </span>
                        @if ($server->size)
                            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-brand-moss">
                                <span class="text-[10px] uppercase tracking-[0.16em] text-brand-mist">{{ __('Size') }}</span>
                                <span class="ml-1.5 font-mono font-semibold text-brand-ink">{{ $server->size }}</span>
                            </span>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Onboarding checklist. Auto-vanishes once every applicable step
                 is done, so a long-lived server's overview is unencumbered.
                 Container hosts get a shorter list (no SSH-key / monitor /
                 backups items — those don't apply when sites live in a
                 cluster). Collapsed by default once at least one step is
                 done so the operator can fold it away. --}}
            @if (! $onboardingComplete && $onboardingTotal > 0)
                @php $onboardingPct = max(0, min(100, (int) round(100 * $onboardingDone / $onboardingTotal))); @endphp
                <section
                    data-testid="server-onboarding-checklist"
                    x-data="{ open: @js($onboardingDone === 0) }"
                    class="mt-6 overflow-hidden rounded-2xl border border-sky-200 bg-gradient-to-br from-sky-50/80 via-white to-sky-50/40 shadow-sm"
                >
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        class="flex w-full items-center justify-between gap-3 px-5 py-3.5 text-left"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-sky-600 text-xs font-bold text-white shadow-sm">
                                {{ $onboardingDone }}/{{ $onboardingTotal }}
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Get started') }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">
                                    {{ trans_choice('{1} :n step left to make this server useful|[2,*] :n steps left to make this server useful', $onboardingTotal - $onboardingDone, ['n' => $onboardingTotal - $onboardingDone]) }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="hidden w-32 sm:block">
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/5">
                                    <div class="h-full rounded-full bg-sky-500 transition-[width] duration-500" style="width: {{ $onboardingPct }}%"></div>
                                </div>
                            </div>
                            <x-heroicon-m-chevron-down class="h-5 w-5 text-brand-moss transition-transform" x-bind:class="{ 'rotate-180': open }" />
                        </div>
                    </button>
                    <ul x-show="open" x-collapse class="divide-y divide-sky-100 border-t border-sky-100 bg-white/60">
                        @foreach ($onboardingSteps as $step)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
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
                                    <a href="{{ $step['cta_route'] }}" wire:navigate class="shrink-0 inline-flex h-8 items-center justify-center gap-1 rounded-lg bg-sky-600 px-3 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-sky-700">
                                        {{ $step['cta_label'] }}
                                        <x-heroicon-m-arrow-right class="h-3.5 w-3.5" />
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Live system metrics card. Reads the same ServerMetricSnapshot
                 the Monitor tab uses, just the latest row — three bars and a
                 load number so the operator gets a real "is this box ok"
                 read above the click-through stat tiles. Empty state nudges
                 toward installing the monitor agent. --}}
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
            <section class="mt-6 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="flex items-baseline gap-2">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Live load') }}</p>
                        @if ($metricCapturedAt)
                            <span class="text-[11px] text-brand-mist {{ $metricStale ? 'text-amber-700' : '' }}">
                                {{ __('Sampled :ago', ['ago' => $metricCapturedAt->diffForHumans()]) }}
                                @if ($metricStale)
                                    · <span class="font-semibold uppercase tracking-wide">{{ __('stale') }}</span>
                                @endif
                            </span>
                        @endif
                    </div>
                    <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="text-xs font-medium text-brand-sage hover:text-brand-forest">
                        {{ __('Open Monitor') }} →
                    </a>
                </div>
                @if (! $metricHasAny)
                    <div class="mt-3 rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-4 py-3 text-sm text-brand-moss">
                        {{ __('No metric snapshots yet — install the monitor agent from the Monitor tab so this card lights up.') }}
                    </div>
                @else
                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
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
            </section>

            {{-- SSH-key reminder. Conditional, fires only when the
                 operator's personal profile key isn't yet on the server.
                 Carried over unchanged from the previous design. --}}
            @if (! $serverHasPersonalProfileKey)
                <section class="mt-6 rounded-2xl border border-brand-gold/40 bg-brand-sand/35 p-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-2xl">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Add your personal SSH key before you need this server') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-brand-moss">
                                @if ($hasProfileSshKeys)
                                    {{ __('This server is ready, but it does not yet include one of your personal profile SSH keys. Attach one from the SSH keys workspace and sync authorized_keys so your own login access is on the machine.') }}
                                @else
                                    {{ __('This server is ready, but you do not have any personal SSH keys saved in your profile yet. Add one first, then attach it from the SSH keys workspace so your own login access is on the machine.') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if (! $hasProfileSshKeys)
                                <a href="{{ route('profile.ssh-keys') }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-cream transition-colors hover:bg-brand-forest">
                                    {{ __('Add a profile key') }}
                                </a>
                            @endif
                            <a href="{{ route('servers.ssh-keys', $server) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink transition-colors hover:bg-brand-sand/30">
                                {{ __('Open SSH keys workspace') }}
                            </a>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Container launch progress (extracted partial — also rendered on
                 the /cluster page so K8s users see launch progress in their
                 main destination too). --}}
            <div class="mt-6">
                @include('livewire.servers.partials._container-launch-progress')
            </div>

            {{-- Container-host empty state. Only visible when the host is docker/kubernetes,
                 there are no sites yet, and there's no in-flight launch banner above. The
                 corresponding VM-host welcome / first-site CTA stays in the existing flow.
                 ($isContainerHost is computed above with $setupIncomplete.) --}}
            @if ($isContainerHost && $siteCount === 0 && ! $containerLaunch)
                <section data-testid="add-first-container-cta" class="mt-6 overflow-hidden rounded-[2rem] border border-sky-200 bg-gradient-to-br from-sky-50 via-white to-sky-50/50 p-8 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-6">
                        <div class="max-w-xl space-y-2">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700">{{ __('Next step') }}</p>
                            <h3 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Add your first container app') }}</h3>
                            <p class="text-sm leading-6 text-brand-moss">{{ __('Point dply at a Git repo and we will inspect the Dockerfile, build the image, and deploy onto this host. You can add more apps any time.') }}</p>
                        </div>
                        <a href="{{ route('sites.create', $server) }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-sky-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-sky-700">
                            <x-heroicon-o-plus class="h-4 w-4" />
                            {{ __('Add a container app') }}
                        </a>
                    </div>
                </section>
            @endif

            {{-- 5 click-through stat tiles. Each lands at its dedicated
                 workspace sub-page. xl:grid-cols-5 keeps them on one row
                 instead of stranding the 5th tile alone underneath. --}}
            <section class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
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

                <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm transition hover:border-brand-sage hover:shadow-md">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Health') }}</p>
                    <p class="mt-2 text-xl font-semibold text-brand-ink">{{ $healthValue }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ $healthMeta }}</p>
                    <p class="mt-3 text-[11px] font-medium text-brand-sage opacity-0 transition group-hover:opacity-100">{{ __('Open Monitor →') }}</p>
                </a>

                <a href="{{ route('servers.sites', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm transition hover:border-brand-sage hover:shadow-md">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Sites') }}</p>
                    <p class="mt-2 text-xl font-semibold text-brand-ink">{{ $siteCount }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ $deployingMeta }}</p>
                    <p class="mt-3 text-[11px] font-medium text-brand-sage opacity-0 transition group-hover:opacity-100">{{ __('Open Sites →') }}</p>
                </a>

                <a href="{{ route('servers.databases', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm transition hover:border-brand-sage hover:shadow-md">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Databases') }}</p>
                    <p class="mt-2 text-xl font-semibold text-brand-ink">{{ $databaseSummary['count'] }}</p>
                    <p class="mt-1 text-xs text-brand-moss">
                        @if ($installedStack->database)
                            {{ str($installedStack->database)->headline() }}@if ($installedStack->databaseVersion) · {{ $installedStack->databaseVersion }}@endif
                        @else
                            {{ __('No engine recorded') }}
                        @endif
                    </p>
                    <p class="mt-3 text-[11px] font-medium text-brand-sage opacity-0 transition group-hover:opacity-100">{{ __('Open Databases →') }}</p>
                </a>

                <a href="{{ route('servers.deploys', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm transition hover:border-brand-sage hover:shadow-md">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Latest deploy') }}</p>
                    <p class="mt-2 text-xl font-semibold text-brand-ink">{{ $latestDeployValue }}</p>
                    <p class="mt-1 truncate text-xs text-brand-moss">{{ $latestDeployMeta }}</p>
                    <p class="mt-3 text-[11px] font-medium text-brand-sage opacity-0 transition group-hover:opacity-100">{{ __('Open Deploys →') }}</p>
                </a>

                {{-- Background health — surfaces queue workers + schedules + recent backup failures
                     to the high-traffic Overview page. Click-through lands on Backups, which is
                     where operators typically need to act when this tile shows anything red. --}}
                <a href="{{ route('servers.backups', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm transition hover:border-brand-sage hover:shadow-md">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Background') }}</p>
                    <p class="mt-2 flex items-baseline gap-2">
                        <span class="text-xl font-semibold text-brand-ink">{{ $backgroundSummary['active_workers'] }}</span>
                        <span class="text-xs text-brand-moss">{{ __('workers') }}</span>
                    </p>
                    <p class="mt-1 truncate text-xs text-brand-moss">
                        @if ($backgroundSummary['failed_backups_7d'] > 0)
                            <span class="font-semibold text-red-700">{{ trans_choice('{1} :count failed backup (7d)|[2,*] :count failed backups (7d)', $backgroundSummary['failed_backups_7d'], ['count' => $backgroundSummary['failed_backups_7d']]) }}</span>
                        @elseif ($backgroundSummary['paused_schedules'] > 0)
                            <span class="text-amber-700">{{ trans_choice('{1} :count paused schedule|[2,*] :count paused schedules', $backgroundSummary['paused_schedules'], ['count' => $backgroundSummary['paused_schedules']]) }}</span>
                        @elseif ($backgroundSummary['active_schedules'] > 0)
                            {{ trans_choice('{1} :count active schedule|[2,*] :count active schedules', $backgroundSummary['active_schedules'], ['count' => $backgroundSummary['active_schedules']]) }}
                        @else
                            {{ __('No schedules yet') }}
                        @endif
                    </p>
                    <p class="mt-3 text-[11px] font-medium text-brand-sage opacity-0 transition group-hover:opacity-100">{{ __('Open Backups →') }}</p>
                </a>
            </section>

            {{-- Sites preview — top 5 by last-touched, with each site's most
                 recent deploy status. Lets the operator scan "what is
                 actually running on this box" without bouncing to the Sites
                 tab. Empty-state path is handled above (first-container
                 CTA for K8s/Docker, the existing VM first-site CTA elsewhere). --}}
            @if ($sitesPreview->isNotEmpty())
                <section class="mt-6 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Sites') }}</p>
                        <a href="{{ route('servers.sites', $server) }}" wire:navigate class="text-xs font-medium text-brand-sage hover:text-brand-forest">
                            {{ __('Open Sites') }} →
                        </a>
                    </div>
                    <ul class="mt-3 divide-y divide-brand-ink/10">
                        @foreach ($sitesPreview as $previewSite)
                            @php
                                $deploy = $sitesPreviewLatestDeploys[$previewSite->id] ?? null;
                                $deployStatus = $deploy?->status ? (string) $deploy->status : null;
                                $deployTime = $deploy ? ($deploy->finished_at ?? $deploy->created_at) : null;
                                $statusBadge = match ($previewSite->status) {
                                    'active', 'ready' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
                                    'deploying', 'queued' => 'bg-sky-50 text-sky-800 ring-sky-200',
                                    'failed', 'error' => 'bg-rose-50 text-rose-800 ring-rose-200',
                                    default => 'bg-brand-sand/40 text-brand-moss ring-brand-ink/10',
                                };
                            @endphp
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <div class="min-w-0">
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $previewSite]) }}" wire:navigate class="block truncate font-medium text-brand-ink hover:text-brand-sage">
                                        {{ $previewSite->name }}
                                    </a>
                                    @if ($deployTime)
                                        <p class="mt-0.5 text-[11px] text-brand-mist">
                                            {{ __('Last deploy :time', ['time' => $deployTime->diffForHumans()]) }}
                                            @if ($deployStatus)
                                                <span class="text-brand-mist"> · </span>
                                                <span class="text-brand-moss">{{ str($deployStatus)->headline() }}</span>
                                            @endif
                                        </p>
                                    @else
                                        <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('No deploys yet') }}</p>
                                    @endif
                                </div>
                                <span class="shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $statusBadge }}">
                                    {{ $previewSite->status ?? '—' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($siteCount > $sitesPreview->count())
                        <p class="mt-3 text-xs text-brand-mist">
                            {{ __('Showing :n of :total — open Sites to see the rest.', ['n' => $sitesPreview->count(), 'total' => $siteCount]) }}
                        </p>
                    @endif
                </section>
            @endif

            {{-- Stack summary card. One line of installed-runtime
                 facts (database engine + version, php, webserver, cache)
                 with a low-memory-mode badge when applicable. Reads via
                 InstalledStack::fromMeta so legacy servers degrade
                 gracefully to wizard meta. --}}
            <section class="mt-6 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Stack') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                            @if ($installedStack->database)
                                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/20 px-2.5 py-1 font-medium text-brand-ink">
                                    {{ str($installedStack->database)->headline() }}@if ($installedStack->databaseVersion)<span class="ml-1 font-mono text-xs text-brand-moss">{{ $installedStack->databaseVersion }}</span>@endif
                                </span>
                            @endif
                            @if ($installedStack->phpVersion)
                                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/20 px-2.5 py-1 font-medium text-brand-ink">
                                    PHP <span class="ml-1 font-mono text-xs text-brand-moss">{{ $installedStack->phpVersion }}</span>
                                </span>
                            @endif
                            @if ($installedStack->webserver)
                                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/20 px-2.5 py-1 font-medium text-brand-ink">
                                    {{ str($installedStack->webserver)->headline() }}
                                </span>
                            @endif
                            @if ($installedStack->cacheService && $installedStack->cacheService !== 'none')
                                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/20 px-2.5 py-1 font-medium text-brand-ink">
                                    {{ str($installedStack->cacheService)->headline() }}
                                </span>
                            @endif
                            @if ($installedStack->lowMemoryMode)
                                <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800" title="{{ __('Provisioned in low-memory mode — substituted lighter services where possible.') }}">
                                    <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Low-memory mode') }}
                                </span>
                            @endif
                        </div>
                        @if ($installedStackDiverges)
                            <p class="mt-2 text-xs text-amber-700">
                                {{ __('Wizard requested :requested but :installed was installed instead. See journey for context.', [
                                    'requested' => $server->meta['database'] ?? '—',
                                    'installed' => $installedStack->database ?? '—',
                                ]) }}
                            </p>
                        @endif
                    </div>
                    <a href="{{ route('servers.services', $server) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-forest">{{ __('Open Services →') }}</a>
                </div>
            </section>

            {{-- Conditional cards: Insights + Notifications. Each only
                 renders when there's something to surface (open insights,
                 attached channels). Empty-state operators see a cleaner
                 page; populated-state operators get the high-signal
                 summary at a glance. --}}
            @if ($openInsightsCount > 0)
                <section class="mt-4 rounded-2xl border {{ $criticalInsightsCount > 0 ? 'border-red-200 bg-red-50/40' : 'border-amber-200 bg-amber-50/40' }} p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] {{ $criticalInsightsCount > 0 ? 'text-red-700' : 'text-amber-700' }}">{{ __('Insights') }}</p>
                            <p class="mt-2 text-sm font-semibold text-brand-ink">
                                {{ trans_choice('{1} :count open finding|[2,*] :count open findings', $openInsightsCount, ['count' => $openInsightsCount]) }}
                                @if ($criticalInsightsCount > 0)
                                    <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">
                                        {{ trans_choice('{1} :count critical|[2,*] :count critical', $criticalInsightsCount, ['count' => $criticalInsightsCount]) }}
                                    </span>
                                @endif
                            </p>
                        </div>
                        <a href="{{ route('servers.insights', $server) }}" wire:navigate class="text-sm font-medium {{ $criticalInsightsCount > 0 ? 'text-red-700 hover:text-red-900' : 'text-amber-700 hover:text-amber-900' }}">
                            {{ __('Open Insights →') }}
                        </a>
                    </div>
                </section>
            @endif

            @if ($notificationSummary['manage_url'])
                <section class="mt-4 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Notifications') }}</p>
                            <p class="mt-2 text-sm text-brand-ink">
                                @if ($notificationSummary['channel_count'] > 0)
                                    {{ trans_choice('{1} :count channel routing this server|[2,*] :count channels routing this server', $notificationSummary['channel_count'], ['count' => $notificationSummary['channel_count']]) }}
                                @else
                                    {{ __('No channels routing yet — add one to get pinged when something matters.') }}
                                @endif
                            </p>
                        </div>
                        <a href="{{ $notificationSummary['manage_url'] }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-forest">
                            {{ __('Manage →') }}
                        </a>
                    </div>
                </section>
            @endif
        @endif
    </div>

    @if (! $setupIncomplete)
        @can('delete', $server)
            <section class="overflow-hidden rounded-2xl border-2 border-red-300 bg-red-50/60 shadow-sm">
                <div class="border-b border-red-200 bg-red-100/60 px-5 py-2.5">
                    <p class="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.2em] text-red-800">
                        <x-heroicon-m-exclamation-triangle class="h-4 w-4" />
                        {{ __('Danger zone') }}
                    </p>
                </div>
                <div class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-start sm:justify-between">
                    <div class="max-w-2xl min-w-0">
                        <h3 class="text-base font-semibold text-red-900">{{ __('Remove this server') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-red-900/80">
                            {{ __('Deletes the dply server record, runs any provider teardown, and detaches sites / databases / backups. You\'ll be asked to type the server name to confirm and can schedule removal for a future date (runs at the end of that day in your app timezone).') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="openRemoveServerModal"
                        class="shrink-0 inline-flex h-10 items-center justify-center gap-1.5 rounded-xl bg-red-600 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-red-700"
                    >
                        <x-heroicon-o-trash class="h-4 w-4" />
                        {{ __('Remove or schedule removal') }}
                    </button>
                </div>
            </section>
        @endcan
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
