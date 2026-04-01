<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex justify-between items-center flex-wrap gap-2">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ $site->name }}</h2>
                <p class="text-sm text-slate-500">
                    {{ $server->name }} · {{ $site->type->label() }}
                    @if ($site->workspace)
                        · {{ __('Project:') }}
                        <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="font-medium text-slate-700 hover:text-slate-900">
                            {{ $site->workspace->name }}
                        </a>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-4">
                @if ($site->workspace)
                    <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 text-slate-600 hover:text-slate-900 text-sm font-medium">
                        {{ __('Project') }}
                    </a>
                    <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 text-slate-600 hover:text-slate-900 text-sm font-medium">
                        {{ __('Project delivery') }}
                    </a>
                @endif
                <a href="{{ route('sites.insights', [$server, $site]) }}" wire:navigate class="inline-flex items-center gap-1.5 text-slate-600 hover:text-slate-900 text-sm font-medium">
                    {{ __('Insights') }}
                    @if ($openSiteInsightsCount > 0)
                        <span class="inline-flex min-w-[1.25rem] justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[11px] font-semibold leading-none text-white" title="{{ trans_choice(':count open finding|:count open findings', $openSiteInsightsCount, ['count' => $openSiteInsightsCount]) }}">{{ $openSiteInsightsCount }}</span>
                    @endif
                </a>
                <a href="{{ route('servers.show', $server) }}" class="text-slate-500 hover:text-slate-700 text-sm">← Server</a>
            </div>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($flash_success)
                <div class="p-4 rounded-md bg-green-50 text-green-800">{{ $flash_success }}</div>
            @endif
            @if ($flash_error)
                <div class="p-4 rounded-md bg-red-50 text-red-800">{{ $flash_error }}</div>
            @endif

            @if ($this->deployLockInfo)
                <div class="p-4 rounded-md bg-amber-50 text-amber-900 text-sm border border-amber-200" wire:poll.5s>
                    <strong>Deployment in progress</strong>
                    @if (! empty($this->deployLockInfo['deployment_id']))
                        <span class="text-amber-800">· run #{{ $this->deployLockInfo['deployment_id'] }}</span>
                    @endif
                    <p class="mt-1 text-amber-800">Queued deploys may appear as <span class="font-medium">skipped</span> until this run finishes.</p>
                    <button type="button" wire:click="openConfirmActionModal('releaseDeployLock', [], @js(__('Clear deploy lock')), @js(__('Force-clear the deploy lock? Only if no worker is actually deploying.')), @js(__('Clear lock')), true)" class="mt-2 text-sm text-amber-900 underline">Clear lock</button>
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-3">Status</h3>
                @if ($site->workspace)
                    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <p class="font-medium text-slate-900">{{ __('Project context') }}</p>
                        <p class="mt-1">
                            {{ __('This site rolls up into the :project project.', ['project' => $site->workspace->name]) }}
                            <a href="{{ route('projects.operations', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open project operations') }}</a>
                            {{ __('for grouped health and activity, or') }}
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('open project delivery') }}</a>
                            {{ __('to coordinate releases and shared variables.') }}
                        </p>
                    </div>
                @endif
                <p class="text-sm text-slate-600 mb-3">
                    {{ __('Show this site on a public') }}
                    <a href="{{ route('status-pages.index') }}" class="text-slate-800 font-medium hover:underline">{{ __('status page') }}</a>.
                </p>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-slate-500">Provisioning</dt><dd class="font-medium capitalize">{{ str_replace('_', ' ', $site->status) }}</dd></div>
                    <div><dt class="text-slate-500">SSL</dt><dd class="font-medium capitalize">{{ $site->ssl_status }}</dd></div>
                    <div><dt class="text-slate-500">Document root (configured)</dt><dd class="font-mono text-xs break-all">{{ $site->document_root }}</dd></div>
                    <div><dt class="text-slate-500">Deploy path</dt><dd class="font-mono text-xs break-all">{{ $site->effectiveRepositoryPath() }}</dd></div>
                    <div><dt class="text-slate-500">Nginx web root</dt><dd class="font-mono text-xs break-all">{{ $site->effectiveDocumentRootForNginx() }}</dd></div>
                    <div><dt class="text-slate-500">Deploy strategy</dt><dd class="font-medium">{{ $site->deploy_strategy }}</dd></div>
                    @if (!empty($site->meta['site_health_last_check_at']))
                        <div><dt class="text-slate-500">URL health (scheduler)</dt><dd class="font-medium">
                            @if (!empty($site->meta['site_health_last_ok']))
                                <span class="text-green-700">OK</span>
                            @else
                                <span class="text-red-700">Failed</span>
                            @endif
                            <span class="text-slate-500 text-xs font-normal"> · {{ $site->meta['site_health_last_check_at'] ?? '' }}</span>
                        </dd></div>
                    @endif
                </dl>
            </div>

            @php
                $supportedInstalledPhpVersions = collect($sitePhpData['installed_versions'])
                    ->filter(fn (array $version) => (bool) ($version['is_supported'] ?? false))
                    ->values();
            @endphp

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="font-medium text-slate-900">PHP</h3>
                        <p class="mt-1 text-sm text-slate-600">Choose a site PHP version from the supported versions currently installed on this server and keep site-owned runtime limits here. OPcache, Composer auth, and extension management stay shared and server-owned on the server PHP workspace.</p>
                    </div>
                    <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 hover:text-slate-900">
                        {{ __('Open server PHP workspace') }}
                    </a>
                </div>

                @if ($sitePhpData['mismatch_version'])
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
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
                        <dt class="text-slate-500">Current site version</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ $sitePhpData['current_version_label'] ?? 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Installed on this server</dt>
                        <dd class="mt-1 font-medium text-slate-900">
                            @if ($supportedInstalledPhpVersions->isNotEmpty())
                                {{ $supportedInstalledPhpVersions->pluck('label')->implode(', ') }}
                            @else
                                {{ __('No supported installed versions recorded yet') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">OPcache</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ __('Shared at the server level; review runtime config on the server PHP workspace.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Composer auth</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ __('Shared Composer credentials are managed from the server PHP workspace.') }}</dd>
                    </div>
                </dl>

                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                    <p class="font-medium text-slate-900">{{ __('Extensions') }}</p>
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

                    <x-primary-button type="submit">Save PHP settings</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-3">Domains</h3>
                <ul class="divide-y divide-slate-100 mb-4">
                    @foreach ($site->domains as $d)
                        <li class="py-2 flex justify-between items-center gap-2">
                            <span class="font-mono text-sm">{{ $d->hostname }} @if ($d->is_primary)<span class="text-slate-400">(primary)</span>@endif</span>
                            @if (! $d->is_primary)
                                <button type="button" wire:click="openConfirmActionModal('removeDomain', ['{{ $d->id }}'], @js(__('Remove domain')), @js(__('Remove this domain?')), @js(__('Remove domain')), true)" class="text-red-600 text-sm hover:underline">Remove</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <form wire:submit="addDomain" class="flex flex-wrap gap-2 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <x-input-label for="new_domain_hostname" value="Add domain" />
                        <x-text-input id="new_domain_hostname" wire:model="new_domain_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="www.example.com" />
                        <x-input-error :messages="$errors->get('new_domain_hostname')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" class="!py-2">Add</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Nginx (HTTP)</h3>
                <p class="text-sm text-slate-600">Writes a vhost under <code class="bg-slate-100 px-1 rounded text-xs">sites-available</code>, symlinks to <code class="bg-slate-100 px-1 rounded text-xs">sites-enabled</code>, runs <code class="bg-slate-100 px-1 rounded text-xs">nginx -t</code> and reloads. Server must have Nginx installed; PHP sites need matching PHP-FPM.</p>
                @if ($server->isReady() && $server->ssh_private_key)
                    <button type="button" wire:click="installNginx" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="installNginx">Install / update Nginx site</span>
                        <span wire:loading wire:target="installNginx" class="inline-flex items-center gap-2">
                            <x-spinner variant="white" size="sm" />
                            Working…
                        </span>
                    </button>
                @else
                    <p class="text-sm text-amber-700">SSH key required on the server record.</p>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Let’s Encrypt (Certbot)</h3>
                <p class="text-sm text-slate-600">Run after HTTP vhost works and DNS points here. Uses <code class="bg-slate-100 px-1 rounded text-xs">certbot --nginx</code>. Set <code class="bg-slate-100 px-1 rounded text-xs">DPLY_CERTBOT_EMAIL</code> in <code class="bg-slate-100 px-1 rounded text-xs">.env</code> or ensure your user/org has an email.</p>
                @if ($server->isReady() && $server->ssh_private_key)
                    <button type="button" wire:click="issueSsl" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-emerald-800 text-white text-sm font-medium rounded-md hover:bg-emerald-900 disabled:opacity-50">
                        <span wire:loading.remove wire:target="issueSsl">Issue / renew SSL</span>
                        <span wire:loading wire:target="issueSsl" class="inline-flex items-center gap-2">
                            <x-spinner variant="white" size="sm" />
                            Certbot…
                        </span>
                    </button>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Git & deploy</h3>
                <form wire:submit="saveGit" class="space-y-3">
                    <div>
                        <x-input-label for="git_repository_url" value="Repository URL" />
                        <x-text-input id="git_repository_url" wire:model="git_repository_url" class="mt-1 block w-full font-mono text-sm" placeholder="git@github.com:org/repo.git" />
                    </div>
                    <div>
                        <x-input-label for="git_branch" value="Branch" />
                        <x-text-input id="git_branch" wire:model="git_branch" class="mt-1 block w-full w-48" />
                    </div>
                    <div>
                        <x-input-label for="post_deploy_command" value="Post-deploy command (after pipeline steps below)" />
                        <textarea id="post_deploy_command" wire:model="post_deploy_command" rows="3" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-sm" placeholder="composer install --no-dev && php artisan migrate --force"></textarea>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-primary-button type="submit">Save</x-primary-button>
                        <button type="button" wire:click="generateDeployKey" class="px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Generate deploy key</button>
                    </div>
                </form>

                <div class="border-t border-slate-100 pt-4 mt-4 space-y-3">
                    <h4 class="text-sm font-medium text-slate-900">Deploy pipeline</h4>
                    <p class="text-sm text-slate-600">Optional ordered steps run on the server after the <code class="text-xs bg-slate-100 px-1 rounded">after_clone</code> hooks and before the post-deploy command. On <strong>atomic</strong> deploys they run in the new release directory before the <code class="text-xs bg-slate-100 px-1 rounded">current</code> symlink is updated.</p>
                    @if ($site->deploySteps->isNotEmpty())
                        <ol class="list-decimal list-inside text-sm space-y-2 text-slate-800">
                            @foreach ($site->deploySteps->sortBy('sort_order') as $step)
                                <li class="flex flex-wrap justify-between gap-2 items-start border-b border-slate-50 pb-2">
                                    <span>
                                        <span class="font-mono text-xs">{{ $step->step_type }}</span>
                                        <span class="text-slate-400 text-xs"> · {{ (int) ($step->timeout_seconds ?? 900) }}s</span>
                                        @if ($step->custom_command)
                                            <span class="text-slate-500"> — {{ \Illuminate\Support\Str::limit($step->custom_command, 80) }}</span>
                                        @endif
                                    </span>
                                    <span class="flex gap-2 shrink-0">
                                        <button type="button" wire:click="moveDeployStepUp({{ $step->id }})" class="text-slate-600 text-xs hover:underline">Up</button>
                                        <button type="button" wire:click="moveDeployStepDown({{ $step->id }})" class="text-slate-600 text-xs hover:underline">Down</button>
                                        <button type="button" wire:click="deleteDeployPipelineStep({{ $step->id }})" class="text-red-600 text-xs hover:underline">Remove</button>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    <form wire:submit="addDeployPipelineStep" class="flex flex-wrap gap-2 items-end">
                        <div>
                            <label for="new_deploy_step_type" class="block text-xs font-medium text-slate-600 mb-1">Step</label>
                            <select id="new_deploy_step_type" wire:model="new_deploy_step_type" class="rounded-md border-slate-300 shadow-sm text-sm min-w-[200px]">
                                @foreach (\App\Models\SiteDeployStep::typeLabels() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex-1 min-w-[180px]">
                            <label for="new_deploy_step_command" class="block text-xs font-medium text-slate-600 mb-1">npm script / custom (if needed)</label>
                            <input type="text" id="new_deploy_step_command" wire:model="new_deploy_step_command" class="w-full rounded-md border-slate-300 shadow-sm text-sm font-mono" placeholder="build or full shell for custom" />
                            <x-input-error :messages="$errors->get('new_deploy_step_command')" class="mt-1" />
                        </div>
                        <div>
                            <label for="new_deploy_step_timeout" class="block text-xs font-medium text-slate-600 mb-1">Timeout (s)</label>
                            <input type="number" id="new_deploy_step_timeout" wire:model="new_deploy_step_timeout" min="30" max="3600" class="w-24 rounded-md border-slate-300 shadow-sm text-sm" />
                        </div>
                        <x-primary-button type="submit" class="!py-2">Add step</x-primary-button>
                    </form>
                </div>
                @if ($site->git_deploy_key_public)
                    <div>
                        <p class="text-sm text-slate-600 mb-1">Public key (add to GitHub / GitLab deploy keys):</p>
                        <pre class="bg-slate-900 text-green-400 p-3 rounded text-xs overflow-x-auto whitespace-pre-wrap">{{ $site->git_deploy_key_public }}</pre>
                    </div>
                @endif
                <div class="flex flex-wrap gap-2 pt-2">
                    <button type="button" wire:click="deployNow" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="deployNow">Deploy now (sync)</span>
                        <span wire:loading wire:target="deployNow" class="inline-flex items-center gap-2">
                            <x-spinner variant="white" size="sm" />
                            Deploying…
                        </span>
                    </button>
                    <button type="button" wire:click="queueDeploy" class="px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Queue deploy (queue worker)</button>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Deployment &amp; Nginx tuning</h3>
                <p class="text-sm text-slate-600"><strong>Atomic</strong> deploys clone into <code class="text-xs bg-slate-100 px-1 rounded">releases/&lt;timestamp&gt;</code> and flip a <code class="text-xs bg-slate-100 px-1 rounded">current</code> symlink. Nginx web root becomes <code class="text-xs bg-slate-100 px-1 rounded">…/current/public</code>. Enable Laravel scheduler here, then sync crontab on the server page.</p>
                @if ($site->workspace)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <p class="font-medium text-slate-900">{{ __('Project delivery context') }}</p>
                        <p class="mt-1">
                            {{ __('This site belongs to the :project project.', ['project' => $site->workspace->name]) }}
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open project delivery') }}</a>
                            {{ __('to review shared variables, coordinated deploy batches, and delivery notes before changing this site.') }}
                        </p>
                    </div>
                @endif
                <form wire:submit="saveDeploymentSettings" class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label value="Deploy strategy" />
                            <select wire:model="deploy_strategy" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                <option value="simple">Simple (git in deploy path)</option>
                                <option value="atomic">Atomic (releases + current symlink)</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="releases_to_keep" value="Releases to keep" />
                            <x-text-input id="releases_to_keep" type="number" wire:model="releases_to_keep" class="mt-1 w-24" min="1" max="50" />
                        </div>
                        <div>
                            <x-input-label for="deployment_environment" value="Env group (for key/value vars)" />
                            <x-text-input id="deployment_environment" wire:model="deployment_environment" class="mt-1 block w-full text-sm" />
                        </div>
                        <div>
                            <x-input-label for="octane_port" value="Octane port (PHP sites only; proxies to Swoole/RoadRunner)" />
                            <x-text-input id="octane_port" wire:model="octane_port" placeholder="8000" class="mt-1 block w-full font-mono text-sm" />
                        </div>
                        <div>
                            <x-input-label for="php_fpm_user" value="PHP-FPM pool user (note in config)" />
                            <x-text-input id="php_fpm_user" wire:model="php_fpm_user" class="mt-1 block w-full text-sm" placeholder="www-data" />
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="laravel_scheduler" class="rounded border-slate-300">
                        Laravel scheduler (<code class="text-xs bg-slate-100 px-1">schedule:run</code> every minute via server crontab)
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="restart_supervisor_programs_after_deploy" class="rounded border-slate-300">
                        Restart Supervisor programs after successful deploy (programs linked to this site or server-wide on the same machine)
                    </label>
                    <div>
                        <x-input-label for="nginx_extra_raw" value="Extra Nginx inside server block (advanced)" />
                        <textarea id="nginx_extra_raw" wire:model="nginx_extra_raw" rows="4" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="# location /foo { ... }"></textarea>
                    </div>
                    <x-primary-button type="submit">Save</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Environment variables (key / value)</h3>
                <p class="text-sm text-slate-600">Merged with project-level variables and the raw .env draft below for the selected environment. Values are encrypted in Dply.</p>
                @if ($site->workspace && $site->workspace->variables->isNotEmpty())
                    <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
                        <p class="font-medium">{{ __('Inherited project variables') }}</p>
                        <p class="mt-1 text-sky-900">{{ __('These values are merged into the final .env for this site. Keep shared values on the project, then add a site variable only when this site needs an override.') }}</p>
                        <ul class="mt-3 space-y-1">
                            @foreach ($site->workspace->variables as $projectVariable)
                                <li>
                                    <span class="font-mono text-xs">{{ $projectVariable->env_key }}</span>
                                    <span class="text-sky-800">·</span>
                                    <span>{{ $projectVariable->is_secret ? __('secret') : __('shared value') }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-3">
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-sky-950 hover:underline">{{ __('Manage project variables') }}</a>
                        </p>
                    </div>
                @endif
                @if ($site->environmentVariables->isNotEmpty())
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($site->environmentVariables as $ev)
                            <li class="py-2 flex justify-between gap-2">
                                <span><span class="font-mono">{{ $ev->env_key }}</span> <span class="text-slate-400">({{ $ev->environment }})</span> = <span class="text-slate-600">••••</span></span>
                                <button type="button" wire:click="deleteEnvironmentVariable({{ $ev->id }})" class="text-red-600 text-xs hover:underline">Remove</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="addEnvironmentVariable" class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-end">
                    <div>
                        <x-input-label for="new_env_key" value="KEY" />
                        <x-text-input id="new_env_key" wire:model="new_env_key" class="mt-1 font-mono text-sm" placeholder="APP_DEBUG" />
                        <x-input-error :messages="$errors->get('new_env_key')" />
                    </div>
                    <div>
                        <x-input-label for="new_env_value" value="Value" />
                        <x-text-input id="new_env_value" wire:model="new_env_value" class="mt-1 font-mono text-sm" type="password" autocomplete="off" />
                    </div>
                    <div>
                        <x-input-label for="new_env_environment" value="Environment" />
                        <x-text-input id="new_env_environment" wire:model="new_env_environment" class="mt-1 text-sm" />
                    </div>
                    <div class="sm:col-span-3">
                        <x-primary-button type="submit" class="!py-2">Save variable</x-primary-button>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Redirects (exact path)</h3>
                <p class="text-sm text-slate-600">Creates <code class="text-xs bg-slate-100 px-1">location = /path</code> blocks. Re-run Install Nginx after changes.</p>
                @if ($site->redirects->isNotEmpty())
                    <ul class="text-sm space-y-1">
                        @foreach ($site->redirects as $r)
                            <li class="flex justify-between gap-2 font-mono text-xs">
                                <span>{{ $r->from_path }} → {{ $r->to_url }} ({{ $r->status_code }})</span>
                                <button type="button" wire:click="deleteRedirectRule({{ $r->id }})" class="text-red-600 hover:underline shrink-0">Remove</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="addRedirectRule" class="flex flex-wrap gap-2 items-end">
                    <x-text-input wire:model="new_redirect_from" placeholder="/old" class="font-mono text-sm w-32" />
                    <x-text-input wire:model="new_redirect_to" placeholder="https://…" class="font-mono text-sm flex-1 min-w-[200px]" />
                    <select wire:model.number="new_redirect_code" class="rounded-md border-slate-300 text-sm">
                        <option value="301">301</option>
                        <option value="302">302</option>
                        <option value="307">307</option>
                        <option value="308">308</option>
                    </select>
                    <x-primary-button type="submit" class="!py-2">Add</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Deploy hooks (bash)</h3>
                <p class="text-sm text-slate-600"><strong>before_clone</strong> runs in the deploy base directory. <strong>after_clone</strong> in the new release. <strong>after_activate</strong> after the <code class="text-xs bg-slate-100 px-1">current</code> symlink updates (atomic only).</p>
                @if ($site->deployHooks->isNotEmpty())
                    <ul class="space-y-2 text-sm">
                        @foreach ($site->deployHooks as $h)
                            <li class="border border-slate-100 rounded p-2">
                                <div class="flex justify-between mb-1">
                                    <span class="font-medium">{{ $h->phase }} #{{ $h->sort_order }} <span class="text-slate-500 font-normal">· {{ (int) ($h->timeout_seconds ?? config('dply.default_deploy_hook_timeout_seconds', 900)) }}s</span></span>
                                    <button type="button" wire:click="deleteDeployHook({{ $h->id }})" class="text-red-600 text-xs hover:underline">Remove</button>
                                </div>
                                <pre class="text-xs bg-slate-900 text-green-400 p-2 rounded overflow-x-auto whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($h->script, 500) }}</pre>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="addDeployHook" class="space-y-2">
                    <select wire:model="new_hook_phase" class="rounded-md border-slate-300 text-sm">
                        <option value="before_clone">before_clone</option>
                        <option value="after_clone">after_clone</option>
                        <option value="after_activate">after_activate</option>
                    </select>
                    <div class="flex flex-wrap gap-2 items-center">
                        <x-text-input type="number" wire:model="new_hook_order" class="w-24 text-sm" title="sort order" />
                        <div>
                            <label class="block text-xs text-slate-600 mb-0.5">Timeout (s)</label>
                            <input type="number" wire:model="new_hook_timeout_seconds" min="30" max="3600" class="w-24 rounded-md border-slate-300 shadow-sm text-sm" />
                        </div>
                    </div>
                    <textarea wire:model="new_hook_script" rows="4" class="w-full rounded-md border-slate-300 font-mono text-xs" placeholder="#!/usr/bin/env bash"></textarea>
                    <x-primary-button type="submit" class="!py-2">Add hook</x-primary-button>
                </form>
            </div>

            @if ($site->deploy_strategy === 'atomic')
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                    <h3 class="font-medium text-slate-900">Releases &amp; rollback</h3>
                    @if ($site->releases->isEmpty())
                        <p class="text-sm text-slate-500">No recorded releases yet. Deploy once with atomic strategy.</p>
                    @else
                        <ul class="text-sm space-y-2">
                            @foreach ($site->releases as $rel)
                                <li class="flex justify-between items-center border border-slate-100 rounded px-3 py-2">
                                    <div>
                                        <span class="font-mono text-xs">{{ $rel->folder }}</span>
                                        @if ($rel->is_active)<span class="text-green-600 text-xs ml-2">active</span>@endif
                                        @if ($rel->git_sha)<div class="font-mono text-xs text-slate-500">{{ $rel->git_sha }}</div>@endif
                                    </div>
                                    @if (! $rel->is_active)
                                        <button type="button" wire:click="openConfirmActionModal('rollbackRelease', ['{{ $rel->id }}'], @js(__('Rollback release')), @js(__('Point current symlink at this release?')), @js(__('Rollback')), true)" class="text-slate-800 text-xs hover:underline">Rollback</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Deploy webhook</h3>
                <p class="text-sm text-slate-600"><strong>Recommended:</strong> send <code class="text-xs bg-slate-100 px-1 rounded">X-Dply-Timestamp</code> (unix seconds) and <code class="text-xs bg-slate-100 px-1 rounded">X-Dply-Signature: sha256=&lt;hmac&gt;</code> where HMAC is <code class="text-xs bg-slate-100 px-1 rounded">hash_hmac('sha256', "{timestamp}." . raw_body, secret)</code>. Replays of the same payload within 15 minutes return <code class="text-xs">409</code>. <strong>Legacy:</strong> signature over raw body only (no timestamp) is still accepted.</p>
                <p class="text-sm font-mono break-all bg-slate-50 p-2 rounded">{{ $deployHookUrl }}</p>
                @if ($revealed_webhook_secret)
                    <p class="text-sm text-amber-800 font-medium">Copy your new secret now:</p>
                    <pre class="bg-slate-900 text-amber-200 p-3 rounded text-xs overflow-x-auto">{{ $revealed_webhook_secret }}</pre>
                @else
                    <p class="text-sm text-slate-500">Secret is stored encrypted. Rotate to see a new one.</p>
                @endif
                <button type="button" wire:click="regenerateWebhookSecret" class="text-sm text-slate-700 underline">Rotate webhook secret</button>
                <form wire:submit="saveWebhookSecurity" class="space-y-2 border-t border-slate-100 pt-4 mt-4">
                    <x-input-label for="webhook_allowed_ips_text" value="Optional IP allow list (one IPv4/IPv6 or IPv4 CIDR per line)" />
                    <textarea id="webhook_allowed_ips_text" wire:model="webhook_allowed_ips_text" rows="4" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="203.0.113.10&#10;192.0.2.0/24"></textarea>
                    <x-input-error :messages="$errors->get('webhook_allowed_ips_text')" class="mt-1" />
                    <x-primary-button type="submit" class="!py-2 text-sm">Save allow list</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Webhook delivery log</h3>
                <p class="text-sm text-slate-600">Recent inbound deploy webhook attempts (signature checks, IP allow list, etc.).</p>
                @if ($site->webhookDeliveryLogs->isEmpty())
                    <p class="text-sm text-slate-500">No deliveries recorded yet.</p>
                @else
                    <ul class="text-xs font-mono space-y-1 border border-slate-100 rounded-md divide-y divide-slate-100">
                        @foreach ($site->webhookDeliveryLogs as $log)
                            <li class="px-3 py-2 flex flex-wrap gap-2 justify-between">
                                <span>{{ $log->created_at->diffForHumans() }}</span>
                                <span class="text-slate-600">{{ $log->request_ip ?? '—' }}</span>
                                <span class="text-slate-800">{{ $log->http_status }} · {{ $log->outcome }}</span>
                                @if ($log->detail)
                                    <span class="text-slate-500 w-full">{{ $log->detail }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3" wire:poll.10s>
                <h3 class="font-medium text-slate-900">Deployment log</h3>
                @if ($site->workspace)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <p class="font-medium text-slate-900">{{ __('Project delivery context') }}</p>
                        <p class="mt-1">
                            {{ __('Use this log for site-specific output, then') }}
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('open project delivery') }}</a>
                            {{ __('to coordinate shared deploy batches, compare related site rollouts, and review project-level delivery notes for :project.', ['project' => $site->workspace->name]) }}
                        </p>
                    </div>
                @endif
                @if ($site->deployments->isEmpty())
                    <p class="text-sm text-slate-500">No deployments yet.</p>
                @else
                    <ul class="space-y-4">
                        @foreach ($site->deployments as $dep)
                            <li class="border border-slate-200 rounded-md p-3 text-sm">
                                <div class="flex flex-wrap justify-between gap-2 mb-2">
                                    @php
                                        $st = $dep->status;
                                        $cls = match ($st) {
                                            'success' => 'text-green-700',
                                            'failed' => 'text-red-700',
                                            'skipped' => 'text-amber-700',
                                            'running' => 'text-blue-700',
                                            default => 'text-slate-700',
                                        };
                                    @endphp
                                    <span class="font-medium capitalize">{{ $dep->trigger }} · <span class="{{ $cls }}">{{ $st }}</span></span>
                                    <span class="text-slate-500 text-xs">{{ $dep->created_at->diffForHumans() }}</span>
                                </div>
                                @if ($dep->git_sha)
                                    <p class="text-xs font-mono text-slate-600 mb-1">{{ $dep->git_sha }}</p>
                                @endif
                                @if ($dep->log_output)
                                    <pre class="bg-slate-900 text-slate-200 p-2 rounded text-xs overflow-x-auto max-h-48 overflow-y-auto whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($dep->log_output, 8000) }}</pre>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Environment (.env)</h3>
                <p class="text-sm text-slate-600">Draft is stored encrypted. Push merges <strong>project variables</strong>, then <strong>site key/value variables</strong> (for <code class="text-xs bg-slate-100 px-1">{{ $site->deployment_environment }}</code>) with this draft and writes <code class="text-xs bg-slate-100 px-1">{{ $site->effectiveEnvDirectory() }}/.env</code>.</p>
                @if ($site->workspace)
                    <p class="text-sm text-slate-500">
                        {{ __('For shared settings across multiple sites in this project, prefer storing them at the project level first.') }}
                        <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-700 hover:text-slate-900">{{ __('Open project delivery') }}</a>
                    </p>
                @endif
                <textarea wire:model="env_file_content" rows="8" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="APP_NAME=…"></textarea>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="saveEnvDraft" class="px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Save draft in Dply</button>
                    <button type="button" wire:click="pushEnvToServer" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="pushEnvToServer">Push .env to server</span>
                        <span wire:loading wire:target="pushEnvToServer" class="inline-flex items-center gap-2">
                            <x-spinner variant="white" size="sm" />
                            Pushing…
                        </span>
                    </button>
                </div>
            </div>

            @can('delete', $site)
                <div class="flex justify-between items-center">
                    <button type="button" wire:click="openConfirmActionModal('deleteSite', [], @js(__('Delete site')), @js(__('Delete this site from Dply? A background job removes Nginx vhost, optional releases/repo/cert (see DPLY_* env flags), supervisor rows tied to this site, deploy SSH key, and re-syncs server crontab.')), @js(__('Delete site')), true)" class="text-red-600 hover:underline text-sm">Delete site</button>
                </div>
            @endcan
        </div>

        <x-slot name="modals">
            @include('livewire.partials.confirm-action-modal')
        </x-slot>
    </div>
</div>
