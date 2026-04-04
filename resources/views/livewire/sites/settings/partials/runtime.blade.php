@php
    $resolvedDetection = $site->resolvedRuntimeAppDetection();
    $detectedFramework = strtolower((string) ($resolvedDetection['framework'] ?? ''));
    $showPhpStackDetails = $site->type === \App\Enums\SiteType::Php
        || in_array($detectedFramework, ['laravel', 'php_generic', 'symfony'], true);
    $showNodeStackDetails = $site->type === \App\Enums\SiteType::Node;
    $showStaticStackDetails = $site->type === \App\Enums\SiteType::Static;
    $showAppPortEditor = ! $functionsHost && (
        $showNodeStackDetails
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
    $showRuntimePreferencesForm = ! $functionsHost;
    $showRailsRuntimeFields = ! $functionsHost && $detectedFramework === 'rails';
    $detectionSourceLabel = match ($resolvedDetection['source'] ?? null) {
        'docker' => __('Docker inspection'),
        'kubernetes' => __('Kubernetes inspection'),
        'serverless' => __('Serverless target'),
        'vm' => __('VM deploy (composer.json)'),
        default => '',
    };
@endphp

@if ($supportsMachinePhp && is_array($sitePhpData) && $site->type === \App\Enums\SiteType::Php)
    @php
        $supportedInstalledPhpVersions = collect($sitePhpData['installed_versions'])
            ->filter(fn (array $version) => (bool) ($version['is_supported'] ?? false))
            ->values();
    @endphp

    <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-8">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('PHP workspace') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Machine-level PHP, extensions, and Composer auth are shared on the server. Below you set this site’s PHP version and per-site limits.') }}</p>
            </div>
            <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="inline-flex shrink-0 items-center gap-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                {{ __('Open server PHP workspace') }}
            </a>
        </div>

        @if ($sitePhpData['mismatch_version'])
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-medium">{{ __('PHP version mismatch') }}</p>
                <p class="mt-1 text-amber-800">{{ __('This site references PHP :version, but that version is not currently installed on this server.', ['version' => $sitePhpData['mismatch_version']]) }}</p>
                <p class="mt-2">
                    <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="font-medium text-amber-900 underline">
                        {{ __('Install or switch versions on the server PHP page') }}
                    </a>
                </p>
            </div>
        @endif

        <div class="space-y-4">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Server PHP status') }}</h3>
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium text-brand-moss">{{ __('Current site version') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">{{ $sitePhpData['current_version_label'] ?? __('Not set') }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium text-brand-moss">{{ __('Installed on this server') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">
                        @if ($supportedInstalledPhpVersions->isNotEmpty())
                            {{ $supportedInstalledPhpVersions->pluck('label')->implode(', ') }}
                        @else
                            {{ __('No supported installed versions recorded yet') }}
                        @endif
                    </dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium text-brand-moss">{{ __('OPcache') }}</dt>
                    <dd class="mt-1 text-brand-ink">{{ __('Shared at server level; tune on the server PHP workspace.') }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium text-brand-moss">{{ __('Composer auth') }}</dt>
                    <dd class="mt-1 text-brand-ink">{{ __('Managed from the server PHP workspace.') }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('Extensions') }}</p>
            <p class="mt-1">{{ __('Extensions are server-owned and shared across sites. Review them on the server PHP workspace.') }}</p>
        </div>

        <div class="space-y-4 border-t border-brand-ink/10 pt-8">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Site PHP limits') }}</h3>
            <form wire:submit="savePhpSettings" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <x-input-label for="php_version" value="PHP version" />
                        <select id="php_version" wire:model="php_version" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                            @foreach ($supportedInstalledPhpVersions as $version)
                                <option value="{{ $version['id'] }}">{{ $version['label'] }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('php_version')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="php_memory_limit" value="Memory limit" />
                        <x-text-input id="php_memory_limit" wire:model="php_memory_limit" class="mt-1 block w-full font-mono text-sm" placeholder="512M" />
                        <x-input-error :messages="$errors->get('php_memory_limit')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="php_upload_max_filesize" value="Upload max filesize" />
                        <x-text-input id="php_upload_max_filesize" wire:model="php_upload_max_filesize" class="mt-1 block w-full font-mono text-sm" placeholder="64M" />
                        <x-input-error :messages="$errors->get('php_upload_max_filesize')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="php_max_execution_time" value="Max execution time" />
                        <x-text-input id="php_max_execution_time" wire:model="php_max_execution_time" class="mt-1 block w-full font-mono text-sm" placeholder="120" />
                        <x-input-error :messages="$errors->get('php_max_execution_time')" class="mt-1" />
                    </div>
                </div>

                <x-primary-button type="submit">{{ __('Save PHP settings') }}</x-primary-button>
            </form>
        </div>
    </section>
@endif

<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-6">
    <div class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold text-brand-ink">
                @if ($site->usesDockerRuntime())
                    {{ __('Container runtime') }}
                @elseif ($site->usesKubernetesRuntime())
                    {{ __('Cluster runtime') }}
                @elseif ($site->usesFunctionsRuntime())
                    {{ __('Function runtime') }}
                @else
                    {{ __('Runtime overview') }}
                @endif
            </h2>
            <p class="mt-1 text-sm text-brand-moss">
                @if ($functionsHost)
                    {{ __('Functions-backed apps expose inspectable runtime details here. Repository controls, build output, and rollout behavior live in Deploy.') }}
                @else
                    {{ __('Execution target, paths, and environment group. Dply detects PHP, Node, Python (Django, Flask, FastAPI), Ruby, and static sites from your repo when available. Adjust preferences below; Git and deploy pipeline stay in Deploy.') }}
                @endif
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/50 px-3 py-1 text-xs font-medium text-brand-ink" title="{{ __('Host target') }}">
                {{ $site->runtimeTargetLabel() }}
            </span>
            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-3 py-1 text-xs font-medium text-brand-ink">
                {{ $site->runtimeExecutionModeLabel() }}
            </span>
            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-3 py-1 text-xs font-medium text-brand-ink">
                {{ $site->type->label() }}
            </span>
            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-3 py-1 text-xs font-medium text-brand-ink">
                {{ $site->runtimeProfileLabel() }}
            </span>
        </div>
    </div>

    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Working directory') }}</p>
        <p class="mt-2 break-all font-mono text-xs text-brand-ink">{{ $site->effectiveRepositoryPath() }}</p>
    </div>

    @if ($showRuntimePreferencesForm)
        <form wire:submit="saveRuntimePreferences" class="space-y-6 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm sm:p-6">
            <div>
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Runtime preferences') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('These values apply across deploys for this site. Re-install or reload webserver config when prompted after changing ports or paths.') }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="runtime_deployment_environment" :value="__('Environment group')" />
                    <x-text-input id="runtime_deployment_environment" wire:model="deployment_environment" class="mt-1 block w-full text-sm" placeholder="production" />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Used when resolving keyed environment variables for deploys.') }}</p>
                    <x-input-error :messages="$errors->get('deployment_environment')" class="mt-1" />
                </div>

                @if ($showStaticStackDetails)
                    <div class="sm:col-span-2">
                        <x-input-label for="runtime_settings_document_root" :value="__('Web directory / published path')" />
                        <x-text-input id="runtime_settings_document_root" wire:model="settings_document_root" class="mt-1 block w-full font-mono text-sm" placeholder="/var/www/app/public" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Document root for static HTML and assets served by the web server.') }}</p>
                        <x-input-error :messages="$errors->get('settings_document_root')" class="mt-1" />
                    </div>
                @endif

                @if ($showAppPortEditor)
                    <div>
                        <x-input-label for="runtime_app_port_input" :value="__('App listens on (localhost)')" />
                        <x-text-input id="runtime_app_port_input" type="number" wire:model="runtime_app_port" class="mt-1 block w-full max-w-[10rem] font-mono text-sm" placeholder="3000" min="1" max="65535" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Reverse proxy target: Node, Rails/Puma, or container app port on the host.') }}</p>
                        <x-input-error :messages="$errors->get('runtime_app_port')" class="mt-1" />
                    </div>
                @endif
            </div>

            @if ($showRailsRuntimeFields)
                <div class="space-y-4 border-t border-brand-ink/10 pt-6">
                    <h4 class="text-sm font-semibold text-brand-ink">{{ __('Rails') }}</h4>
                    <div class="max-w-md">
                        <x-input-label for="rails_env" :value="__('RAILS_ENV')" />
                        <x-text-input id="rails_env" wire:model="rails_env" class="mt-1 block w-full font-mono text-sm" placeholder="production" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Stored on the site for deploy scripts and operator reference. Align with your Puma/Thruster and systemd configuration. You can also edit this under Deploy → Rollout and web server.') }}</p>
                        <x-input-error :messages="$errors->get('rails_env')" class="mt-1" />
                    </div>
                </div>
            @endif

            @if ($showPhpStackDetails)
                <div class="space-y-4 border-t border-brand-ink/10 pt-6">
                    <h4 class="text-sm font-semibold text-brand-ink">{{ $site->runtimePhpProcessSectionTitle() }}</h4>
                    @include('livewire.sites.settings.partials.laravel.octane-fields', ['site' => $site])

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @if (! $this->shouldShowSystemUserPanel())
                            <div>
                                <x-input-label for="runtime_php_fpm_user" :value="__('PHP-FPM pool user')" />
                                <x-text-input id="runtime_php_fpm_user" wire:model="php_fpm_user" class="mt-1 block w-full text-sm" placeholder="www-data" />
                                <x-input-error :messages="$errors->get('php_fpm_user')" class="mt-1" />
                            </div>
                        @endif
                        <div class="flex flex-col justify-end gap-3 sm:col-span-2 lg:col-span-2">
                            <div>
                                <label class="flex items-center gap-2 text-sm text-brand-ink">
                                    <input type="checkbox" wire:model="laravel_scheduler" class="rounded border-slate-300 text-brand-forest shadow-sm focus:ring-brand-forest">
                                    {{ $site->runtimeSchedulerCheckboxLabel() }}
                                </label>
                                @if ($site->runtimeSchedulerCheckboxHelp())
                                    <p class="mt-1 pl-6 text-xs text-brand-moss">{{ $site->runtimeSchedulerCheckboxHelp() }}</p>
                                @endif
                            </div>
                            <label class="flex items-center gap-2 text-sm text-brand-ink">
                                <input type="checkbox" wire:model="restart_supervisor_programs_after_deploy" class="rounded border-slate-300 text-brand-forest shadow-sm focus:ring-brand-forest">
                                {{ __('Restart Supervisor programs after successful deploy') }}
                            </label>
                        </div>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3 border-t border-brand-ink/10 pt-6">
                <x-primary-button type="submit">{{ __('Save runtime preferences') }}</x-primary-button>
            </div>
        </form>
    @endif

    @if ($resolvedDetection)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/40 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Detected from repository') }}</p>
                    @if ($detectionSourceLabel !== '')
                        <p class="mt-1 text-xs text-brand-moss">{{ $detectionSourceLabel }}</p>
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
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('No repository inspection yet') }}</p>
            <p class="mt-1">{{ __('After a deploy or container inspect, framework and language signals from your repo will appear here.') }}</p>
        </div>
    @endif

    @if ($functionsHost)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('Deploy tab') }}</p>
            <p class="mt-1">{{ __('Repository, build commands, serverless runtime, and artifacts are configured under Deploy for this host.') }}</p>
        </div>
    @else
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('Also in Deploy') }}</p>
            <p class="mt-1">{{ __('Git remote, branch, zero-downtime releases, hooks, Nginx snippets, and scripts remain in the Deploy tab.') }}</p>
        </div>
    @endif

    @if ($site->usesDockerRuntime())
        @if ($runtimeErrorConsole)
            <div class="space-y-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Runtime errors') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('The latest failure or error-focused diagnostics captured for this runtime.') }}</p>
                </div>

                @include('livewire.partials.deployment-activity-console', [
                    'title' => __('Runtime errors'),
                    'meta' => $runtimeErrorConsole['meta'],
                    'transcript' => $runtimeErrorConsole['transcript'],
                    'maxHeight' => '20rem',
                ])
            </div>
        @endif

        @if ($dockerContainers->isNotEmpty() || $runtimePublication !== [])
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Docker discovery') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Saved from the live Docker runtime so hostname, IP, and container identity stay referenceable later.') }}</p>
                    </div>
                    @if (! empty($dockerRuntimeDetails['collected_at']))
                        <p class="font-mono text-[11px] text-brand-moss">{{ __('Collected :time', ['time' => $dockerRuntimeDetails['collected_at']]) }}</p>
                    @endif
                </div>

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
        @endif

        @if ($site->usesLocalDockerHostRuntime())
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 space-y-5">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Runtime management') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Lifecycle and diagnostics for the local container runtime behind this app.') }}</p>
                </div>

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
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Diagnostics') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="runRuntimeAction('inspect')" class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-800 hover:bg-sky-100">{{ __('Refresh Docker details') }}</button>
                        <button type="button" wire:click="runRuntimeAction('errors')" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100">{{ __('Errors') }}</button>
                        <button type="button" wire:click="runRuntimeAction('status')" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">{{ __('Status') }}</button>
                        <button type="button" wire:click="runRuntimeAction('logs')" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">{{ __('Logs') }}</button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 pt-4">
                    <p class="text-xs text-brand-moss">{{ __('Removes managed local containers and artifacts for this app.') }}</p>
                    <button type="button" wire:click="openConfirmActionModal('runRuntimeAction', ['destroy'], @js(__('Destroy runtime')), @js(__('Destroy the managed local runtime artifacts and containers for this app?')), @js(__('Destroy runtime')), true)" class="rounded-xl border border-red-200 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">{{ __('Destroy') }}</button>
                </div>

                @if ($runtimeOperationConsoles->isNotEmpty())
                    <div class="space-y-3 border-t border-brand-ink/10 pt-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Recent runtime operations') }}</p>
                        @foreach ($runtimeOperationConsoles as $runtimeConsole)
                            @include('livewire.partials.deployment-activity-console', [
                                'title' => $runtimeConsole['title'],
                                'meta' => $runtimeConsole['meta'],
                                'transcript' => $runtimeConsole['transcript'],
                                'maxHeight' => '18rem',
                            ])
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif
</section>
