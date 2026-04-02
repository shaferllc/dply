@if ($supportsMachinePhp && is_array($sitePhpData) && $site->type === \App\Enums\SiteType::Php)
    @php
        $supportedInstalledPhpVersions = collect($sitePhpData['installed_versions'])
            ->filter(fn (array $version) => (bool) ($version['is_supported'] ?? false))
            ->values();
    @endphp

    <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('PHP') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Choose a site PHP version from the supported versions currently installed on this server and keep site-owned runtime limits here. OPcache, Composer auth, and extension management stay shared and server-owned on the server PHP workspace.') }}</p>
            </div>
            <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
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

        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <dt class="text-brand-moss">{{ __('Current site version') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">{{ $sitePhpData['current_version_label'] ?? __('Not set') }}</dd>
            </div>
            <div>
                <dt class="text-brand-moss">{{ __('Installed on this server') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">
                    @if ($supportedInstalledPhpVersions->isNotEmpty())
                        {{ $supportedInstalledPhpVersions->pluck('label')->implode(', ') }}
                    @else
                        {{ __('No supported installed versions recorded yet') }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-brand-moss">{{ __('OPcache') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">{{ __('Shared at the server level; review runtime config on the server PHP workspace.') }}</dd>
            </div>
            <div>
                <dt class="text-brand-moss">{{ __('Composer auth') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">{{ __('Shared Composer credentials are managed from the server PHP workspace.') }}</dd>
            </div>
        </dl>

        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('Extensions') }}</p>
            <p class="mt-1">{{ __('Extensions are server-owned and shared across sites on this machine. Use the server PHP workspace to review versions and extension entry points.') }}</p>
        </div>

        <form wire:submit="savePhpSettings" class="space-y-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
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
    </section>
@endif

<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-slate-900">
            @if ($site->usesDockerRuntime())
                {{ __('Container runtime') }}
            @elseif ($site->usesKubernetesRuntime())
                {{ __('Cluster runtime') }}
            @elseif ($site->usesFunctionsRuntime())
                {{ __('Function runtime') }}
            @else
                {{ __('Runtime') }}
            @endif
        </h2>
        <p class="mt-1 text-sm text-slate-600">
            @if ($functionsHost)
                {{ __('Functions-backed apps expose inspectable runtime details here. Repository controls, build output, and rollout behavior now live in Deploy.') }}
            @else
                {{ __('Keep runtime-specific details and management here so the Deploy tab can stay focused on code delivery, rollout strategy, scripts, and hooks.') }}
            @endif
        </p>
    </div>

    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-slate-500">{{ __('Runtime profile') }}</dt>
            <dd class="mt-1 font-medium text-slate-900">{{ str((string) ($site->meta['runtime_profile'] ?? $site->type->value ?? __('Unknown')))->replace('_', ' ')->title() }}</dd>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-slate-500">{{ __('Working directory') }}</dt>
            <dd class="mt-1 break-all font-mono text-xs text-slate-900">{{ $site->effectiveRepositoryPath() }}</dd>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-slate-500">{{ __('Env group') }}</dt>
            <dd class="mt-1 font-medium text-slate-900">{{ $deployment_environment !== '' ? $deployment_environment : __('production') }}</dd>
        </div>
        @if (! $functionsHost)
            <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                <dt class="text-brand-moss">{{ __('Octane port') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">{{ $octane_port !== '' ? $octane_port : __('Not set') }}</dd>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                <dt class="text-brand-moss">{{ __('PHP-FPM user') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">{{ $php_fpm_user !== '' ? $php_fpm_user : __('Default') }}</dd>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                <dt class="text-brand-moss">{{ __('Scheduler + Supervisor') }}</dt>
                <dd class="mt-1 text-sm text-brand-ink">{{ $laravel_scheduler ? __('Scheduler enabled') : __('Scheduler disabled') }} · {{ $restart_supervisor_programs_after_deploy ? __('Restart after deploy enabled') : __('No Supervisor restart') }}</dd>
            </div>
        @endif
    </dl>

    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
        <p class="font-medium text-slate-900">{{ __('Runtime editing moved') }}</p>
        <p class="mt-1">{{ __('Repository changes, rollout strategy, hooks, and deploy scripts now live in the Deploy tab so this page can stay focused on how the app runs once it is live.') }}</p>
    </div>

    @if ($site->usesDockerRuntime())
        @if ($runtimeErrorConsole)
            <div class="space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Runtime errors') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('The latest failure or error-focused diagnostics captured for this runtime.') }}</p>
                    </div>
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
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">{{ __('Docker discovery') }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Saved from the live Docker runtime so hostname, IP, and container identity stay referenceable later.') }}</p>
                    </div>
                    @if (! empty($dockerRuntimeDetails['collected_at']))
                        <p class="font-mono text-[11px] text-slate-500">{{ __('Collected :time', ['time' => $dockerRuntimeDetails['collected_at']]) }}</p>
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
                                <thead class="bg-slate-50">
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
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-slate-900">{{ __('Runtime management') }}</h3>
                    <p class="mt-1 text-sm text-slate-600">{{ __('Manage the real local runtime behind this app without going through the old SSH bridge.') }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="runRuntimeAction('rebuild')" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">{{ __('Rebuild') }}</button>
                    <button type="button" wire:click="runRuntimeAction('start')" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('Start') }}</button>
                    <button type="button" wire:click="runRuntimeAction('stop')" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('Stop') }}</button>
                    <button type="button" wire:click="runRuntimeAction('restart')" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('Restart') }}</button>
                    <button type="button" wire:click="runRuntimeAction('inspect')" class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-700 hover:bg-sky-100">{{ __('Refresh Docker details') }}</button>
                    <button type="button" wire:click="runRuntimeAction('errors')" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100">{{ __('Errors') }}</button>
                    <button type="button" wire:click="runRuntimeAction('status')" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('Status') }}</button>
                    <button type="button" wire:click="runRuntimeAction('logs')" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('Logs') }}</button>
                    <button type="button" wire:click="openConfirmActionModal('runRuntimeAction', ['destroy'], @js(__('Destroy runtime')), @js(__('Destroy the managed local runtime artifacts and containers for this app?')), @js(__('Destroy runtime')), true)" class="rounded-xl border border-red-200 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">{{ __('Destroy') }}</button>
                </div>

                @if ($runtimeOperationConsoles->isNotEmpty())
                    <div class="space-y-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Recent runtime operations') }}</p>
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
