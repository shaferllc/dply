@php
    $resolvedDetection = $site->resolvedRuntimeAppDetection();
    $detectedFramework = strtolower((string) ($resolvedDetection['framework'] ?? ''));
    $detectionSourceLabel = match ($resolvedDetection['source'] ?? null) {
        'docker' => __('Docker inspection'),
        'kubernetes' => __('Kubernetes inspection'),
        'serverless' => __('Serverless target'),
        'vm' => __('VM deploy (composer.json)'),
        default => '',
    };
    $showAppPortEditor = ! $functionsHost && (
        $site->type === \App\Enums\SiteType::Node
        || in_array($detectedFramework, [
            'rails',
            'nextjs',
            'nuxt',
            'node_generic',
            'vite_static',
            'django',
            'flask',
            'fastapi',
            'python_generic',
        ], true)
        || $site->usesDockerRuntime()
        || $site->usesKubernetesRuntime()
    );
    $runtimeKey = (string) ($site->runtimeKey() ?? '');
    $runtimeVersion = (string) ($site->runtimeVersion() ?? '');
    $runtimeLabel = match ($runtimeKey) {
        'php' => 'PHP',
        'node' => 'Node.js',
        'python' => 'Python',
        'ruby' => 'Ruby',
        'go' => 'Go',
        'static' => 'Static',
        default => $runtimeKey !== '' ? ucfirst($runtimeKey) : '',
    };
    $runtimeDisplay = $runtimeLabel !== ''
        ? trim($runtimeLabel.' '.$runtimeVersion)
        : __('Not set');
@endphp

{{-- 1. Runtime card --}}
<section class="dply-card overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-cube-transparent class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Runtime') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $runtimeDisplay !== __('Not set') ? $runtimeDisplay : __('Language & version') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('What this site runs and how. Language and version live here; per-language tuning is on the PHP, Ruby, or Static tab when applicable.') }}</p>
            </div>
        </div>
    </div>

    <div class="space-y-6 px-6 py-6 sm:px-7">

    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 sm:col-span-2">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Runtime') }}</dt>
            <dd class="mt-2 text-base font-semibold text-brand-ink">{{ $runtimeDisplay }}</dd>
        </div>
        @if ($site->internal_port)
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Internal port') }}</dt>
                <dd class="mt-2 font-mono text-sm text-brand-ink">127.0.0.1:{{ $site->internal_port }}</dd>
            </div>
        @endif
        @if ($site->start_command)
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 sm:col-span-2 lg:col-span-2">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Start command') }}</dt>
                <dd class="mt-2 break-all font-mono text-xs text-brand-ink">{{ $site->start_command }}</dd>
            </div>
        @endif
    </dl>

    @if ($showAppPortEditor)
        <form wire:submit="saveRuntimePreferences" class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:flex sm:items-end sm:gap-4">
            <div class="flex-1">
                <x-input-label for="runtime_app_port_input" :value="__('App listens on (localhost)')" />
                <x-text-input id="runtime_app_port_input" type="number" wire:model="runtime_app_port" class="mt-1 block w-full max-w-[10rem] font-mono text-sm" placeholder="3000" min="1" max="65535" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Reverse proxy target: Node, Rails/Puma, Python, or container app port on the host.') }}</p>
                <x-input-error :messages="$errors->get('runtime_app_port')" class="mt-1" />
            </div>
            <div class="mt-3 sm:mt-0">
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    @endif
    </div>
</section>

{{-- 2. Detection panel --}}
<section class="mt-6 dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-magnifying-glass-circle class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Detection') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Repository detection') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('What Dply inferred from your repository. Detection runs on deploy and container inspect.') }}</p>
        </div>
    </div>

    <div class="space-y-4 px-6 py-6 sm:px-7">

    @if ($resolvedDetection)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/40 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    @if ($detectionSourceLabel !== '')
                        <p class="text-xs text-brand-moss">{{ __('Source') }}: {{ $detectionSourceLabel }}</p>
                    @endif
                </div>
                @if (! empty($resolvedDetection['confidence']))
                    <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss ring-1 ring-brand-ink/10">
                        {{ strtoupper((string) $resolvedDetection['confidence']) }}
                    </span>
                @endif
            </div>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Framework') }}</dt>
                    <dd class="mt-1 text-sm font-medium text-brand-ink">{{ str((string) ($resolvedDetection['framework'] ?? '—'))->replace('_', ' ')->title() }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Language') }}</dt>
                    <dd class="mt-1 text-sm font-medium text-brand-ink">{{ str((string) ($resolvedDetection['language'] ?? '—'))->replace('_', ' ')->title() }}</dd>
                </div>
                @if (! empty($resolvedDetection['laravel_octane']))
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Laravel Octane') }}</dt>
                        <dd class="mt-1 text-sm font-medium text-brand-ink">{{ __('Yes — `laravel/octane` in composer.json') }}</dd>
                    </div>
                @endif
                @if (! empty($resolvedDetection['laravel_horizon']))
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Laravel Horizon') }}</dt>
                        <dd class="mt-1 text-sm font-medium text-brand-ink">{{ __('Yes — `laravel/horizon` in composer.json') }}</dd>
                    </div>
                @endif
                @if (! empty($resolvedDetection['laravel_pulse']))
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Laravel Pulse') }}</dt>
                        <dd class="mt-1 text-sm font-medium text-brand-ink">{{ __('Yes — `laravel/pulse` in composer.json') }}</dd>
                    </div>
                @endif
                @if (! empty($resolvedDetection['laravel_reverb']))
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Laravel Reverb') }}</dt>
                        <dd class="mt-1 text-sm font-medium text-brand-ink">{{ __('Yes — `laravel/reverb` in composer.json') }}</dd>
                    </div>
                @endif
            </dl>
            @if (! empty($resolvedDetection['warnings']))
                <div class="mt-4 space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    @foreach ($resolvedDetection['warnings'] as $warning)
                        <p>{{ $warning }}</p>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('No repository inspection yet') }}</p>
            <p class="mt-1">{{ __('After a deploy or container inspect, framework and language signals from your repo will appear here.') }}</p>
        </div>
    @endif
    </div>
</section>

{{-- Background processes callout --}}
@if ($site->type !== \App\Enums\SiteType::Static)
<section class="mt-6 dply-card overflow-hidden">
    <div class="flex items-start gap-3 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Background') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Workers & schedulers') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                @if (in_array((string) ($site->runtime ?? ''), ['php'], true) || $site->isLaravelFrameworkDetected())
                    {{ __('Queue workers and Horizon run under Workers (Supervisor). Scheduled tasks use Cron or the Laravel tab.') }}
                @elseif ($site->isRailsFrameworkDetected())
                    {{ __('Sidekiq and Solid Queue run under Workers (Supervisor). Optional systemd workers are on the Services page.') }}
                @else
                    {{ __('App servers: set start command and port above. Workers can use systemd (Services) or Supervisor (Workers).') }}
                @endif
            </p>
            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm font-semibold">
                <a href="{{ route('sites.daemons', ['server' => $server, 'site' => $site]) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Workers') }} →</a>
                <a href="{{ route('sites.cron', ['server' => $server, 'site' => $site]) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Cron jobs') }} →</a>
                @if (\App\Models\Site::supportsSystemdServices($site, $server))
                    <a href="{{ route('sites.services', ['server' => $server, 'site' => $site]) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Services (systemd)') }} →</a>
                @endif
                @if ($site->isLaravelFrameworkDetected())
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'laravel-stack']) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Laravel') }} →</a>
                @endif
                @if ($site->isRailsFrameworkDetected())
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'rails-stack']) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Rails') }} →</a>
                @endif
            </div>
        </div>
    </div>
</section>
@endif

{{-- 4. Container lifecycle (Docker only) --}}
@if ($site->usesDockerRuntime())
    @if ($dockerContainers->isNotEmpty() || $runtimePublication !== [])
        <section class="mt-6 dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-cube class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Container') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Docker discovery') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Saved from the live Docker runtime so hostname, IP, and container identity stay referenceable later.') }}</p>
                    </div>
                </div>
                @if (! empty($dockerRuntimeDetails['collected_at']))
                    <p class="shrink-0 font-mono text-[11px] text-brand-moss">{{ __('Collected :time', ['time' => $dockerRuntimeDetails['collected_at']]) }}</p>
                @endif
            </div>

            <div class="space-y-4 px-6 py-6 sm:px-7">

            <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Hostname') }}</dt>
                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['hostname'] ?? '—' }}</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Container IP') }}</dt>
                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['container_ip'] ?? '—' }}</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Container name') }}</dt>
                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['container_name'] ?? '—' }}</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Service') }}</dt>
                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['docker_service'] ?? '—' }}</dd>
                </div>
            </dl>

            @if ($dockerContainers->isNotEmpty())
                <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white">
                    <div class="border-b border-brand-ink/10 px-4 py-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Containers') }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left">
                            <thead class="bg-brand-sand/40">
                                <tr>
                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Name') }}</th>
                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Service') }}</th>
                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Hostname') }}</th>
                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('IP') }}</th>
                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('State') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                @foreach ($dockerContainers as $container)
                                    <tr>
                                        <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['name'] ?? '—' }}</td>
                                        <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['service'] ?? '—' }}</td>
                                        <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['orb_hostname'] ?? $container['hostname'] ?? '—' }}</td>
                                        <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['ipv4'] ?? '—' }}</td>
                                        <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['state'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            </div>
        </section>
    @endif

    @if ($site->usesLocalDockerHostRuntime())
        <section class="mt-6 dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-arrows-pointing-out class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Lifecycle') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Container lifecycle') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Lifecycle and inspection for the local container runtime behind this app. Output and historical operations live on the Logs tab.') }}</p>
                </div>
            </div>

            <div class="space-y-5 px-6 py-6 sm:px-7">

            <div>
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Lifecycle') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="runRuntimeAction('rebuild')" class="rounded-xl bg-brand-ink px-4 py-2 text-sm font-medium text-white hover:bg-brand-ink/90">{{ __('Rebuild') }}</button>
                    <button type="button" wire:click="runRuntimeAction('start')" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">{{ __('Start') }}</button>
                    <button type="button" wire:click="runRuntimeAction('stop')" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">{{ __('Stop') }}</button>
                    <button type="button" wire:click="runRuntimeAction('restart')" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">{{ __('Restart') }}</button>
                </div>
            </div>

            <div>
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Inspection') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="runRuntimeAction('inspect')" class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-800 hover:bg-sky-100">{{ __('Refresh Docker details') }}</button>
                    <button type="button" wire:click="runRuntimeAction('status')" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">{{ __('Status') }}</button>
                </div>
                <p class="mt-2 text-xs text-brand-moss">{{ __('Logs and recent runtime errors are on the Logs tab.') }}</p>
            </div>

            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
                <p class="text-xs text-brand-moss">{{ __('Removes managed local containers and artifacts for this app.') }}</p>
                <button type="button" wire:click="openConfirmActionModal('runRuntimeAction', ['destroy'], @js(__('Destroy runtime')), @js(__('Destroy the managed local runtime artifacts and containers for this app?')), @js(__('Destroy runtime')), true)" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-50">{{ __('Destroy') }}</button>
            </div>
        </section>
    @endif
@endif

{{-- 5. Working directory footer --}}
<div class="mt-6 dply-card overflow-hidden">
    <div class="flex items-start gap-3 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Path') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Working directory') }}</h3>
            <p class="mt-1 break-all font-mono text-xs text-brand-moss">{{ $site->effectiveRepositoryPath() }}</p>
        </div>
    </div>
</div>

{{-- 6. CLI snippets --}}
<x-cli-snippet :commands="[
    ['label' => __('Set runtime + version'), 'command' => 'dply:site:set-runtime '.$site->slug.' --runtime=node --runtime-version=22'],
    ['label' => __('Set start command + port'), 'command' => 'dply:site:set-runtime '.$site->slug.' --start=\'node server.js\' --port=3000'],
    ['label' => __('Auto-detect from repo'), 'command' => 'dply:detect-runtime '.$site->slug],
    ['label' => __('Show available runtimes'), 'command' => 'dply:list-runtimes --with-usage'],
    ['label' => __('Install runtime on server'), 'command' => 'dply:install-runtime '.($server->name ?? 'SERVER').' node 22'],
]" />
