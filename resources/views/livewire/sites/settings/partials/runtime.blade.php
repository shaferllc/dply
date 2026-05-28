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
    $runtimeSubTab = match ($runtimeKey) {
        'php' => 'runtime-php',
        'ruby' => 'runtime-ruby',
        'static' => 'runtime-static',
        default => null,
    };
@endphp

{{-- 1. Runtime card --}}
<section class="dply-card overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sage/15 text-brand-forest ring-brand-sage/25">
                <x-heroicon-o-cube-transparent class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Runtime') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $runtimeDisplay !== __('Not set') ? $runtimeDisplay : __('Language & version') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('What this site runs and how. Language and version live here; per-language tuning lives in the runtime sub-tab below.') }}</p>
            </div>
        </div>
        @if ($runtimeSubTab !== null)
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => $runtimeSubTab]) }}" wire:navigate class="shrink-0 text-sm font-semibold text-brand-forest hover:text-brand-sage hover:underline">
                {{ __('Open :runtime settings', ['runtime' => ucfirst($runtimeKey)]) }} →
            </a>
        @endif
    </div>

    <div class="space-y-6 p-6 sm:p-8">

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
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-sky-50 text-sky-700 ring-sky-200">
            <x-heroicon-o-magnifying-glass-circle class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Detection') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Repository detection') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('What Dply inferred from your repository. Detection runs on deploy and container inspect.') }}</p>
        </div>
    </div>

    <div class="space-y-4 p-6 sm:p-8">

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

{{-- 3. Site processes — hidden for static sites, which have no SiteProcess rows
     (Site::created skips the auto-web-row for SiteType::Static). Showing the panel
     for static sites would expose a worker/scheduler form that has no meaningful
     systemd unit to back it. --}}
@if ($site->type !== \App\Enums\SiteType::Static)
<section class="mt-6 dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-violet-50 text-violet-700 ring-violet-200">
                <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Processes') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site processes') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('The web row drives the NGINX upstream. Workers and schedulers run as separate systemd units (dply-site-:id-:name.service).', ['id' => $site->id, 'name' => '<name>']) }}</p>
            </div>
        </div>
        @if ($site->processes->isNotEmpty())
            <p class="shrink-0 text-xs text-brand-moss">{{ trans_choice('{1} 1 process|[2,*] :count processes', $site->processes->count(), ['count' => $site->processes->count()]) }}</p>
        @endif
    </div>

    <div class="space-y-4 p-6 sm:p-8">

    @if ($site->processes->isNotEmpty())
        <ul class="space-y-2">
            @foreach ($site->processes as $process)
                <li class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-brand-ink/10 bg-white p-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ $process->type }}</span>
                            <span class="text-sm font-semibold text-brand-ink">{{ $process->name }}</span>
                            @if (! $process->is_active)
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-900">{{ __('inactive') }}</span>
                            @endif
                            @if ((int) $process->scale > 1)
                                <span class="inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold text-sky-900">{{ __('scale :n', ['n' => $process->scale]) }}</span>
                            @endif
                        </div>
                        <p class="mt-1 break-all font-mono text-xs text-brand-moss">{{ $process->command ?? __('(unset — runtime-default applies)') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-xs">
                        @if ($process->type !== 'web')
                            <label class="flex items-center gap-1 text-brand-moss">
                                {{ __('scale') }}
                                <input
                                    type="number"
                                    min="1"
                                    max="16"
                                    value="{{ (int) $process->scale }}"
                                    wire:change="setSiteProcessScale('{{ $process->id }}', $event.target.valueAsNumber)"
                                    class="w-14 rounded border-brand-ink/15 px-2 py-1 text-xs"
                                />
                            </label>
                            <button type="button" wire:click="restartSiteProcess('{{ $process->id }}')" wire:loading.attr="disabled" wire:target="restartSiteProcess" class="font-medium text-sky-700 hover:text-sky-800 disabled:opacity-50">
                                {{ __('Restart') }}
                            </button>
                            <button type="button" wire:click="toggleSiteProcessActive('{{ $process->id }}')" class="font-medium {{ $process->is_active ? 'text-amber-700 hover:text-amber-800' : 'text-emerald-700 hover:text-emerald-800' }}">
                                {{ $process->is_active ? __('Deactivate') : __('Activate') }}
                            </button>
                            <button type="button" wire:click="removeSiteProcess('{{ $process->id }}')" wire:confirm="{{ __('Remove the :name process? Its systemd unit will be torn down on the next deploy.', ['name' => $process->name]) }}" class="font-medium text-rose-700 hover:text-rose-800">{{ __('Remove') }}</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 p-4 text-sm text-brand-moss">
            <p>{{ __('No processes recorded yet. Add a worker or scheduler below, or trigger a deploy so the web process is registered.') }}</p>
        </div>
    @endif

    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/10 p-4">
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Add a process') }}</p>
        <div class="mt-3 grid gap-2 sm:grid-cols-[110px,200px,1fr,auto] sm:items-end">
            <div>
                <label for="new_site_process_type" class="block text-[11px] font-medium text-brand-moss">{{ __('Type') }}</label>
                <select id="new_site_process_type" wire:model="new_site_process_type" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                    <option value="worker">{{ __('worker') }}</option>
                    <option value="scheduler">{{ __('scheduler') }}</option>
                    <option value="custom">{{ __('custom') }}</option>
                </select>
            </div>
            <div>
                <label for="new_site_process_name" class="block text-[11px] font-medium text-brand-moss">{{ __('Name') }}</label>
                <input type="text" id="new_site_process_name" wire:model="new_site_process_name" placeholder="sidekiq" class="mt-1 block w-full rounded-lg border-brand-ink/15 font-mono text-sm shadow-sm" />
                <x-input-error :messages="$errors->get('new_site_process_name')" class="mt-1" />
            </div>
            <div>
                <label for="new_site_process_command" class="block text-[11px] font-medium text-brand-moss">{{ __('Command') }}</label>
                <input type="text" id="new_site_process_command" wire:model="new_site_process_command" placeholder="bundle exec sidekiq -C config/sidekiq.yml" class="mt-1 block w-full rounded-lg border-brand-ink/15 font-mono text-sm shadow-sm" />
                <x-input-error :messages="$errors->get('new_site_process_command')" class="mt-1" />
            </div>
            <x-primary-button type="button" wire:click="addSiteProcess">{{ __('Add') }}</x-primary-button>
        </div>
    </div>
    </div>
</section>
@endif

{{-- 4. Container lifecycle (Docker only) --}}
@if ($site->usesDockerRuntime())
    @if ($dockerContainers->isNotEmpty() || $runtimePublication !== [])
        <section class="mt-6 dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-sky-50 text-sky-700 ring-sky-200">
                        <x-heroicon-o-cube class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Container') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Docker discovery') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Saved from the live Docker runtime so hostname, IP, and container identity stay referenceable later.') }}</p>
                    </div>
                </div>
                @if (! empty($dockerRuntimeDetails['collected_at']))
                    <p class="shrink-0 font-mono text-[11px] text-brand-moss">{{ __('Collected :time', ['time' => $dockerRuntimeDetails['collected_at']]) }}</p>
                @endif
            </div>

            <div class="space-y-4 p-6 sm:p-8">

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
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-700 ring-amber-200">
                    <x-heroicon-o-arrows-pointing-out class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Lifecycle') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Container lifecycle') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Lifecycle and inspection for the local container runtime behind this app. Output and historical operations live on the Logs tab.') }}</p>
                </div>
            </div>

            <div class="space-y-5 p-6 sm:p-8">

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
    <div class="flex items-start gap-3 bg-brand-cream/40 px-5 py-4">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sand/55 text-brand-forest ring-brand-ink/10">
            <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Path') }}</p>
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
