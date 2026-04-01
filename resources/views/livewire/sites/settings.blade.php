@php
    $functionsHost = $server->isDigitalOceanFunctionsHost();
    $supportsMachinePhp = $server->hostCapabilities()->supportsMachinePhpManagement();
    $supportsNginxProvisioning = $server->hostCapabilities()->supportsNginxProvisioning();
    $supportsEnvPush = $server->hostCapabilities()->supportsEnvPushToHost();
    $supportsSshDeployHooks = $server->hostCapabilities()->supportsSshDeployHooks();
    $testingHostname = $site->testingHostname();
    $settingsSidebarItems = [
        ['id' => 'general', 'label' => __('General'), 'icon' => 'heroicon-o-rectangle-stack'],
        ['id' => 'domains', 'label' => __('Domains'), 'icon' => 'heroicon-o-share'],
        ['id' => 'build-and-deploy', 'label' => __('Build & deploy'), 'icon' => 'heroicon-o-code-bracket-square'],
        ['id' => 'runtime', 'label' => __('Runtime'), 'icon' => 'heroicon-o-cube-transparent'],
        ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line'],
        ['id' => 'webhooks', 'label' => __('Webhooks'), 'icon' => 'heroicon-o-clipboard-document-list'],
        ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box'],
    ];
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <nav class="mb-6 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="transition-colors hover:text-brand-ink">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="transition-colors hover:text-brand-ink">{{ __('Servers') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="transition-colors hover:text-brand-ink">{{ $server->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', [$server, $site]) }}" wire:navigate class="transition-colors hover:text-brand-ink">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="font-medium text-brand-ink">{{ __('Site settings') }}</li>
        </ol>
    </nav>

    <div class="space-y-6">
        @if ($flash_success)
            <div class="rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $flash_success }}</div>
        @endif
        @if ($flash_error)
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $flash_error }}</div>
        @endif

        <x-page-header
            :title="__('Site settings')"
            :description="__('Manage this site in focused sections instead of one long operations page.')"
            flush
        >
            <x-slot name="actions">
                <div class="flex items-center gap-3">
                    <a
                        href="{{ route('sites.show', [$server, $site]) }}"
                        wire:navigate
                        class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    >
                        {{ __('Open site overview') }}
                    </a>
                    <a
                        href="{{ route('sites.insights', [$server, $site]) }}"
                        wire:navigate
                        class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    >
                        {{ __('Insights') }}
                    </a>
                </div>
            </x-slot>
        </x-page-header>

        <div class="space-y-6 lg:flex lg:items-start lg:gap-8 lg:space-y-0">
            <aside class="lg:sticky lg:top-8 lg:w-[17rem] lg:flex-none">
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-base font-semibold text-slate-900">{{ optional($site->primaryDomain())->hostname ?? $site->name }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ $server->ip_address ?? __('No IP recorded') }}</p>
                            </div>
                            @if ($site->visitUrl())
                                <a href="{{ $site->visitUrl() }}" target="_blank" rel="noreferrer" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                                    <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                                </a>
                            @endif
                        </div>
                    </div>
                    <nav id="site-settings-sidebar" class="p-4" aria-label="{{ __('Site settings sections') }}">
                        <ul class="space-y-1.5">
                            @foreach ($settingsSidebarItems as $item)
                                <li>
                                    <a
                                        href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => $item['id']]) }}"
                                        wire:navigate
                                        @class([
                                            'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                                            'bg-slate-100 text-slate-900' => $section === $item['id'],
                                            'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => $section !== $item['id'],
                                        ])
                                    >
                                        <x-dynamic-component :component="$item['icon']" class="h-4 w-4 shrink-0" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                            <li class="pt-2">
                                <a
                                    href="{{ route('sites.show', [$server, $site]) }}"
                                    wire:navigate
                                    class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
                                >
                                    <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" />
                                    <span>{{ __('Back to site') }}</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </aside>

            <main class="min-w-0 space-y-6 lg:flex-1">
                <div role="tabpanel" id="site-settings-panel" aria-labelledby="site-settings-sidebar">
                    @if ($section === 'general')
                        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-5">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('General') }}</h2>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('A site-level summary before you move into domains, runtime, and deploy configuration.') }}</p>
                            </div>

                            @if ($site->workspace)
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
                                    <p class="font-medium text-brand-ink">{{ __('Project context') }}</p>
                                    <p class="mt-1">
                                        {{ __('This site rolls up into the :project project.', ['project' => $site->workspace->name]) }}
                                        <a href="{{ route('projects.operations', $site->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ __('Open project operations') }}</a>
                                        {{ __('for grouped health and activity, or') }}
                                        <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ __('open project delivery') }}</a>
                                        {{ __('to coordinate releases and shared variables.') }}
                                    </p>
                                </div>
                            @endif

                            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-brand-moss">{{ __('Provisioning') }}</dt>
                                    <dd class="mt-1 font-medium text-brand-ink capitalize">{{ $site->statusLabel() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-brand-moss">{{ __('Provisioning step') }}</dt>
                                    <dd class="mt-1 font-medium text-brand-ink capitalize">{{ str_replace('_', ' ', $site->provisioningState() ?? 'queued') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-brand-moss">{{ __('SSL') }}</dt>
                                    <dd class="mt-1 font-medium text-brand-ink capitalize">{{ $site->ssl_status }}</dd>
                                </div>
                                <div>
                                    <dt class="text-brand-moss">{{ __('Deploy strategy') }}</dt>
                                    <dd class="mt-1 font-medium text-brand-ink">{{ $site->deploy_strategy }}</dd>
                                </div>
                                <div>
                                    <dt class="text-brand-moss">{{ __('Document root') }}</dt>
                                    <dd class="mt-1 break-all font-mono text-xs text-brand-ink">{{ $site->document_root }}</dd>
                                </div>
                                <div>
                                    <dt class="text-brand-moss">{{ __('Deploy path') }}</dt>
                                    <dd class="mt-1 break-all font-mono text-xs text-brand-ink">{{ $site->effectiveRepositoryPath() }}</dd>
                                </div>
                            </dl>

                            <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                <p class="text-sm font-medium text-brand-ink">{{ __('Testing URL') }}</p>
                                @if ($testingHostname !== '')
                                    <p class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $testingHostname }}</p>
                                    <p class="mt-2 text-sm text-brand-moss">{{ __('Use this URL to test the site before the customer domain points here.') }}</p>
                                @else
                                    <p class="mt-2 text-sm text-brand-moss">{{ __('No temporary testing hostname is configured for this site yet.') }}</p>
                                @endif
                            </div>
                        </section>
                    @elseif ($section === 'domains')
                        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Domains') }}</h2>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Manage customer domains, temporary testing URLs, and HTTP entrypoint details from one place.') }}</p>
                            </div>

                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($site->domains as $domain)
                                    <li class="flex items-center justify-between gap-3 py-3">
                                        <div class="min-w-0">
                                            <p class="truncate font-mono text-sm text-brand-ink">{{ $domain->hostname }}</p>
                                            <p class="mt-1 text-xs text-brand-moss">
                                                @if ($domain->is_primary)
                                                    {{ __('Primary domain') }}
                                                @elseif ($domain->hostname === $testingHostname)
                                                    {{ __('Managed testing hostname') }}
                                                @else
                                                    {{ __('Additional domain') }}
                                                @endif
                                            </p>
                                        </div>
                                        @if (! $domain->is_primary && $domain->hostname !== $testingHostname)
                                            <button type="button" wire:click="confirmRemoveDomain('{{ $domain->id }}')" class="text-sm font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            <form wire:submit="addDomain" class="flex flex-wrap items-end gap-3">
                                <div class="min-w-[220px] flex-1">
                                    <x-input-label for="new_domain_hostname" value="Add domain" />
                                    <x-text-input id="new_domain_hostname" wire:model="new_domain_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="www.example.com" />
                                    <x-input-error :messages="$errors->get('new_domain_hostname')" class="mt-1" />
                                </div>
                                <x-primary-button type="submit">{{ __('Add domain') }}</x-primary-button>
                            </form>
                        </section>

                        @if ($supportsNginxProvisioning)
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                                <div>
                                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('HTTP & SSL') }}</h2>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Apply domain changes to Nginx and request certificates after DNS is pointing at this server.') }}</p>
                                </div>

                                <div class="flex flex-wrap gap-3">
                                    @if ($server->isReady() && $server->ssh_private_key)
                                        <button type="button" wire:click="installNginx" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                                            <span wire:loading.remove wire:target="installNginx">{{ __('Install / update Nginx site') }}</span>
                                            <span wire:loading wire:target="installNginx">{{ __('Working...') }}</span>
                                        </button>
                                        <button type="button" wire:click="issueSsl" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-800 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-900 disabled:opacity-50">
                                            <span wire:loading.remove wire:target="issueSsl">{{ __('Issue / renew SSL') }}</span>
                                            <span wire:loading wire:target="issueSsl">{{ __('Certbot...') }}</span>
                                        </button>
                                    @else
                                        <p class="text-sm text-amber-700">{{ __('SSH key required on the server record.') }}</p>
                                    @endif
                                </div>
                            </section>
                        @endif

                        @if ($supportsNginxProvisioning)
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                                <div>
                                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Redirects') }}</h2>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Exact-path redirects are written into the generated Nginx site config.') }}</p>
                                </div>

                                @if ($site->redirects->isNotEmpty())
                                    <ul class="space-y-2 text-sm">
                                        @foreach ($site->redirects as $redirect)
                                            <li class="flex items-start justify-between gap-3 rounded-xl border border-brand-ink/10 px-4 py-3">
                                                <span class="font-mono text-xs text-brand-ink">{{ $redirect->from_path }} → {{ $redirect->to_url }} ({{ $redirect->status_code }})</span>
                                                <button type="button" wire:click="deleteRedirectRule({{ $redirect->id }})" class="shrink-0 text-sm font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <form wire:submit="addRedirectRule" class="flex flex-wrap items-end gap-3">
                                    <x-text-input wire:model="new_redirect_from" placeholder="/old" class="w-32 font-mono text-sm" />
                                    <x-text-input wire:model="new_redirect_to" placeholder="https://..." class="min-w-[220px] flex-1 font-mono text-sm" />
                                    <select wire:model.number="new_redirect_code" class="rounded-md border-slate-300 text-sm">
                                        <option value="301">301</option>
                                        <option value="302">302</option>
                                        <option value="307">307</option>
                                        <option value="308">308</option>
                                    </select>
                                    <x-primary-button type="submit">{{ __('Add redirect') }}</x-primary-button>
                                </form>
                            </section>
                        @endif
                    @elseif ($section === 'build-and-deploy')
                        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Build & deploy') }}</h2>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Repository selection, build settings, and deploy pipeline controls belong here. Deploy execution itself stays on the site overview page.') }}</p>
                            </div>

                            <form wire:submit="saveGit" class="space-y-4">
                                @if ($functionsHost)
                                    <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
                                        <p class="font-medium">{{ __('Functions deploy target') }}</p>
                                        <p class="mt-1">{{ __('DigitalOcean Functions deploys clone the repository on the queue worker, run the configured build command, package the build output, and publish the resulting zip artifact.') }}</p>
                                    </div>

                                    <div>
                                        <x-input-label for="functions_repo_source" value="Repository source" />
                                        <select id="functions_repo_source" wire:model.live="functions_repo_source" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                            @if (count($linkedSourceControlAccounts) > 0)
                                                <option value="provider">{{ __('Connected Git provider') }}</option>
                                            @endif
                                            <option value="manual">{{ __('Manual Git URL') }}</option>
                                        </select>
                                    </div>

                                    @if ($functions_repo_source === 'provider' && count($linkedSourceControlAccounts) > 0)
                                        <div class="grid gap-3 md:grid-cols-2">
                                            <div>
                                                <x-input-label for="functions_source_control_account_id" value="Connected account" />
                                                <select id="functions_source_control_account_id" wire:model.live="functions_source_control_account_id" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                                    <option value="">{{ __('Select an account') }}</option>
                                                    @foreach ($linkedSourceControlAccounts as $account)
                                                        <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$errors->get('functions_source_control_account_id')" class="mt-1" />
                                            </div>
                                            <div>
                                                <x-input-label for="functions_repository_selection" value="Repository" />
                                                <select id="functions_repository_selection" wire:model.live="functions_repository_selection" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                                    <option value="">{{ __('Select a repository') }}</option>
                                                    @foreach ($availableFunctionsRepositories as $repository)
                                                        <option value="{{ $repository['url'] }}">{{ $repository['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$errors->get('functions_repository_selection')" class="mt-1" />
                                            </div>
                                        </div>
                                    @endif
                                @endif

                                <div>
                                    <x-input-label for="git_repository_url" value="Repository URL" />
                                    <x-text-input id="git_repository_url" wire:model.blur="git_repository_url" class="mt-1 block w-full font-mono text-sm" placeholder="git@github.com:org/repo.git" />
                                    @if ($functionsHost)
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('This repo is cloned locally during deploys instead of on a remote SSH machine.') }}</p>
                                    @endif
                                    <x-input-error :messages="$errors->get('git_repository_url')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="git_branch" value="Branch" />
                                    <x-text-input id="git_branch" wire:model.blur="git_branch" class="mt-1 block w-full sm:w-48" />
                                    <x-input-error :messages="$errors->get('git_branch')" class="mt-1" />
                                </div>
                                @if ($functionsHost)
                                    <div>
                                        <x-input-label for="functions_repository_subdirectory" value="Repository subdirectory" />
                                        <x-text-input id="functions_repository_subdirectory" wire:model.blur="functions_repository_subdirectory" class="mt-1 block w-full font-mono text-sm" placeholder="apps/functions" />
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('Optional for monorepos.') }}</p>
                                        <x-input-error :messages="$errors->get('functions_repository_subdirectory')" class="mt-1" />
                                    </div>
                                @else
                                    <div>
                                        <x-input-label for="post_deploy_command" value="Post-deploy command" />
                                        <textarea id="post_deploy_command" wire:model="post_deploy_command" rows="3" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-sm" placeholder="composer install --no-dev && php artisan migrate --force"></textarea>
                                    </div>
                                @endif

                                @if ($functionsHost && $functionsDetection !== [])
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 space-y-3">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-semibold text-brand-ink">{{ __('Detected setup') }}</p>
                                                <p class="mt-1 text-sm text-brand-moss">{{ __('Dply inspected the configured repository and inferred a starting runtime/build setup for this target.') }}</p>
                                            </div>
                                            <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss ring-1 ring-brand-ink/10">
                                                {{ strtoupper((string) ($functionsDetection['confidence'] ?? 'low')) }}
                                            </span>
                                        </div>
                                        <dl class="grid gap-3 md:grid-cols-2">
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Framework') }}</p>
                                                <p class="mt-1 text-sm font-medium text-brand-ink">{{ str((string) ($functionsDetection['framework'] ?? 'unknown'))->replace('_', ' ')->title() }}</p>
                                            </div>
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Language') }}</p>
                                                <p class="mt-1 text-sm font-medium text-brand-ink">{{ str((string) ($functionsDetection['language'] ?? 'unknown'))->replace('_', ' ')->title() }}</p>
                                            </div>
                                        </dl>
                                        @if (count($functionsDetection['warnings'] ?? []) > 0)
                                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 space-y-2">
                                                @foreach (($functionsDetection['warnings'] ?? []) as $warning)
                                                    <p>{{ $warning }}</p>
                                                @endforeach
                                            </div>
                                        @endif
                                        <details class="rounded-xl border border-brand-ink/10 bg-white p-4" @if(($functionsDetection['unsupported_for_target'] ?? false) || (($functionsDetection['confidence'] ?? '') === 'low')) open @endif>
                                            <summary class="cursor-pointer list-none text-sm font-semibold text-brand-ink">{{ __('Advanced runtime overrides') }}</summary>
                                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                                <div>
                                                    <x-input-label for="functions_runtime" value="Functions runtime" />
                                                    <x-text-input id="functions_runtime" wire:model="functions_runtime" class="mt-1 block w-full font-mono text-sm" />
                                                    <x-input-error :messages="$errors->get('functions_runtime')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="functions_entrypoint" value="HTTP entrypoint" />
                                                    <x-text-input id="functions_entrypoint" wire:model="functions_entrypoint" class="mt-1 block w-full font-mono text-sm" />
                                                    <x-input-error :messages="$errors->get('functions_entrypoint')" class="mt-1" />
                                                </div>
                                                <div class="md:col-span-2">
                                                    <x-input-label for="functions_build_command" value="Build command" />
                                                    <textarea id="functions_build_command" wire:model="functions_build_command" rows="3" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-sm" placeholder="npm install && npm run build"></textarea>
                                                    <x-input-error :messages="$errors->get('functions_build_command')" class="mt-1" />
                                                </div>
                                                <div class="md:col-span-2">
                                                    <x-input-label for="functions_artifact_output_path" value="Build output path" />
                                                    <x-text-input id="functions_artifact_output_path" wire:model="functions_artifact_output_path" class="mt-1 block w-full font-mono text-sm" placeholder="dist" />
                                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Relative to the repo checkout or subdirectory.') }}</p>
                                                    <x-input-error :messages="$errors->get('functions_artifact_output_path')" class="mt-1" />
                                                </div>
                                            </div>
                                        </details>
                                    </div>
                                @endif

                                <div class="flex flex-wrap gap-3">
                                    <x-primary-button type="submit">{{ __('Save repository settings') }}</x-primary-button>
                                    @if (! $functionsHost)
                                        <button type="button" wire:click="generateDeployKey" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Generate deploy key') }}</button>
                                    @endif
                                </div>
                            </form>

                            @if ($functionsHost)
                                @php
                                    $functionsConfig = $site->functionsConfig();
                                @endphp
                                <div class="grid gap-3 rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 md:grid-cols-2">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Runtime') }}</p>
                                        <p class="mt-1 font-mono text-sm text-brand-ink">{{ $functionsConfig['runtime'] ?? $functions_runtime ?? '—' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Entrypoint') }}</p>
                                        <p class="mt-1 font-mono text-sm text-brand-ink">{{ $functionsConfig['entrypoint'] ?? $functions_entrypoint ?? '—' }}</p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Latest managed artifact') }}</p>
                                        <p class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $functionsConfig['artifact_path'] ?? __('Not built yet') }}</p>
                                    </div>
                                    @if (! empty($functionsConfig['action_url']))
                                        <div class="md:col-span-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Published action URL') }}</p>
                                            <p class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $functionsConfig['action_url'] }}</p>
                                        </div>
                                    @endif
                                </div>
                            @elseif ($site->git_deploy_key_public)
                                <div>
                                    <p class="text-sm text-brand-moss">{{ __('Public key (add to your Git provider deploy keys):') }}</p>
                                    <pre class="mt-2 overflow-x-auto rounded-xl bg-slate-900 p-3 text-xs text-green-400">{{ $site->git_deploy_key_public }}</pre>
                                </div>
                            @endif
                        </section>

                        @if (! $functionsHost)
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                                <div>
                                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Deploy pipeline') }}</h2>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Optional ordered steps run after clone and before the post-deploy command.') }}</p>
                                </div>

                                @if ($site->deploySteps->isNotEmpty())
                                    <ol class="space-y-2 text-sm">
                                        @foreach ($site->deploySteps->sortBy('sort_order') as $step)
                                            <li class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-brand-ink/10 px-4 py-3">
                                                <span>
                                                    <span class="font-mono text-xs text-brand-ink">{{ $step->step_type }}</span>
                                                    <span class="text-xs text-brand-moss">· {{ (int) ($step->timeout_seconds ?? 900) }}s</span>
                                                    @if ($step->custom_command)
                                                        <span class="text-brand-moss">— {{ \Illuminate\Support\Str::limit($step->custom_command, 80) }}</span>
                                                    @endif
                                                </span>
                                                <span class="flex gap-3 text-sm">
                                                    <button type="button" wire:click="moveDeployStepUp({{ $step->id }})" class="text-brand-moss hover:underline">{{ __('Up') }}</button>
                                                    <button type="button" wire:click="moveDeployStepDown({{ $step->id }})" class="text-brand-moss hover:underline">{{ __('Down') }}</button>
                                                    <button type="button" wire:click="deleteDeployPipelineStep({{ $step->id }})" class="text-red-700 hover:underline">{{ __('Remove') }}</button>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif

                                <form wire:submit="addDeployPipelineStep" class="flex flex-wrap items-end gap-3">
                                    <div>
                                        <label for="new_deploy_step_type" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Step') }}</label>
                                        <select id="new_deploy_step_type" wire:model="new_deploy_step_type" class="min-w-[220px] rounded-md border-slate-300 shadow-sm text-sm">
                                            @foreach (\App\Models\SiteDeployStep::typeLabels() as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="min-w-[220px] flex-1">
                                        <label for="new_deploy_step_command" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('npm script / custom command') }}</label>
                                        <input type="text" id="new_deploy_step_command" wire:model="new_deploy_step_command" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-sm" placeholder="build or full shell for custom" />
                                        <x-input-error :messages="$errors->get('new_deploy_step_command')" class="mt-1" />
                                    </div>
                                    <div>
                                        <label for="new_deploy_step_timeout" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Timeout (s)') }}</label>
                                        <input type="number" id="new_deploy_step_timeout" wire:model="new_deploy_step_timeout" min="30" max="3600" class="w-24 rounded-md border-slate-300 shadow-sm text-sm" />
                                    </div>
                                    <x-primary-button type="submit">{{ __('Add step') }}</x-primary-button>
                                </form>
                            </section>
                        @endif

                        @if ($supportsSshDeployHooks)
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                                <div>
                                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Deploy hooks') }}</h2>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('before_clone runs in the deploy base directory. after_clone runs in the new release. after_activate runs after the current symlink updates on atomic deploys.') }}</p>
                                </div>

                                @if ($site->deployHooks->isNotEmpty())
                                    <ul class="space-y-3 text-sm">
                                        @foreach ($site->deployHooks as $hook)
                                            <li class="rounded-xl border border-brand-ink/10 p-4">
                                                <div class="mb-2 flex justify-between gap-3">
                                                    <span class="font-medium text-brand-ink">{{ $hook->phase }} #{{ $hook->sort_order }} <span class="font-normal text-brand-moss">· {{ (int) ($hook->timeout_seconds ?? config('dply.default_deploy_hook_timeout_seconds', 900)) }}s</span></span>
                                                    <button type="button" wire:click="deleteDeployHook({{ $hook->id }})" class="text-red-700 hover:underline">{{ __('Remove') }}</button>
                                                </div>
                                                <pre class="overflow-x-auto rounded-xl bg-slate-900 p-3 text-xs text-green-400">{{ \Illuminate\Support\Str::limit($hook->script, 500) }}</pre>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <form wire:submit="addDeployHook" class="space-y-3">
                                    <select wire:model="new_hook_phase" class="rounded-md border-slate-300 text-sm">
                                        <option value="before_clone">before_clone</option>
                                        <option value="after_clone">after_clone</option>
                                        <option value="after_activate">after_activate</option>
                                    </select>
                                    <div class="flex flex-wrap items-end gap-3">
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Sort order') }}</label>
                                            <x-text-input type="number" wire:model="new_hook_order" class="w-24 text-sm" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Timeout (s)') }}</label>
                                            <input type="number" wire:model="new_hook_timeout_seconds" min="30" max="3600" class="w-24 rounded-md border-slate-300 shadow-sm text-sm" />
                                        </div>
                                    </div>
                                    <textarea wire:model="new_hook_script" rows="4" class="w-full rounded-md border-slate-300 font-mono text-xs" placeholder="#!/usr/bin/env bash"></textarea>
                                    <x-primary-button type="submit">{{ __('Add hook') }}</x-primary-button>
                                </form>
                            </section>
                        @endif
                    @elseif ($section === 'runtime')
                        @if ($supportsMachinePhp && is_array($sitePhpData))
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

                        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Runtime & deploy strategy') }}</h2>
                                <p class="mt-1 text-sm text-brand-moss">
                                    @if ($functionsHost)
                                        {{ __('Functions-backed sites keep environment grouping and deploy settings here, but do not use nginx tuning, release symlinks, or server cron integration.') }}
                                    @else
                                        {{ __('Atomic deploys clone into release directories and flip the current symlink after a successful run. Keep machine-backed runtime toggles grouped here.') }}
                                    @endif
                                </p>
                            </div>

                            <form wire:submit="saveDeploymentSettings" class="space-y-4">
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    @if (! $functionsHost)
                                        <div>
                                            <x-input-label value="Deploy strategy" />
                                            <select wire:model="deploy_strategy" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                                <option value="simple">{{ __('Simple (git in deploy path)') }}</option>
                                                <option value="atomic">{{ __('Atomic (releases + current symlink)') }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <x-input-label for="releases_to_keep" value="Releases to keep" />
                                            <x-text-input id="releases_to_keep" type="number" wire:model="releases_to_keep" class="mt-1 w-24" min="1" max="50" />
                                        </div>
                                    @endif
                                    <div>
                                        <x-input-label for="deployment_environment" value="Env group (for key/value vars)" />
                                        <x-text-input id="deployment_environment" wire:model="deployment_environment" class="mt-1 block w-full text-sm" />
                                    </div>
                                    @if (! $functionsHost)
                                        <div>
                                            <x-input-label for="octane_port" value="Octane port" />
                                            <x-text-input id="octane_port" wire:model="octane_port" placeholder="8000" class="mt-1 block w-full font-mono text-sm" />
                                        </div>
                                        <div>
                                            <x-input-label for="php_fpm_user" value="PHP-FPM pool user" />
                                            <x-text-input id="php_fpm_user" wire:model="php_fpm_user" class="mt-1 block w-full text-sm" placeholder="www-data" />
                                        </div>
                                    @endif
                                </div>

                                @if (! $functionsHost)
                                    <label class="flex items-center gap-2 text-sm text-brand-ink">
                                        <input type="checkbox" wire:model="laravel_scheduler" class="rounded border-slate-300">
                                        {{ __('Laravel scheduler (schedule:run every minute via server crontab)') }}
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-brand-ink">
                                        <input type="checkbox" wire:model="restart_supervisor_programs_after_deploy" class="rounded border-slate-300">
                                        {{ __('Restart Supervisor programs after successful deploy') }}
                                    </label>
                                    <div>
                                        <x-input-label for="nginx_extra_raw" value="Extra Nginx inside server block (advanced)" />
                                        <textarea id="nginx_extra_raw" wire:model="nginx_extra_raw" rows="4" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="# location /foo { ... }"></textarea>
                                    </div>
                                @endif

                                <x-primary-button type="submit">{{ __('Save runtime settings') }}</x-primary-button>
                            </form>
                        </section>
                    @elseif ($section === 'environment')
                        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Environment variables') }}</h2>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Merged with project-level variables and the raw .env draft below for the selected environment. Values are encrypted in Dply.') }}</p>
                            </div>

                            @if ($site->workspace && $site->workspace->variables->isNotEmpty())
                                <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
                                    <p class="font-medium">{{ __('Inherited project variables') }}</p>
                                    <p class="mt-1">{{ __('These values are merged into the final .env for this site. Keep shared values on the project, then add a site variable only when this site needs an override.') }}</p>
                                </div>
                            @endif

                            @if ($site->environmentVariables->isNotEmpty())
                                <ul class="divide-y divide-brand-ink/10 text-sm">
                                    @foreach ($site->environmentVariables as $variable)
                                        <li class="flex justify-between gap-3 py-3">
                                            <span><span class="font-mono">{{ $variable->env_key }}</span> <span class="text-brand-moss">({{ $variable->environment }})</span> = <span class="text-brand-moss">••••</span></span>
                                            <button type="button" wire:click="deleteEnvironmentVariable({{ $variable->id }})" class="text-red-700 hover:underline">{{ __('Remove') }}</button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <form wire:submit="addEnvironmentVariable" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <x-input-label for="new_env_key" value="KEY" />
                                    <x-text-input id="new_env_key" wire:model="new_env_key" class="mt-1 font-mono text-sm" placeholder="APP_DEBUG" />
                                    <x-input-error :messages="$errors->get('new_env_key')" class="mt-1" />
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
                                    <x-primary-button type="submit">{{ __('Save variable') }}</x-primary-button>
                                </div>
                            </form>
                        </section>

                        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Environment (.env)') }}</h2>
                                <p class="mt-1 text-sm text-brand-moss">
                                    @if ($supportsEnvPush)
                                        {{ __('Draft is stored encrypted. Push merges project variables, site key/value variables for the active environment, and this draft before writing the server .env file.') }}
                                    @else
                                        {{ __('Draft is stored encrypted in Dply. For Functions-backed sites, keep environment values here and include them in your packaged runtime configuration instead of pushing a machine .env file.') }}
                                    @endif
                                </p>
                            </div>

                            <textarea wire:model="env_file_content" rows="8" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="APP_NAME=..."></textarea>
                            <div class="flex flex-wrap gap-3">
                                <button type="button" wire:click="saveEnvDraft" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Save draft in Dply') }}</button>
                                @if ($supportsEnvPush)
                                    <button type="button" wire:click="pushEnvToServer" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                                        <span wire:loading.remove wire:target="pushEnvToServer">{{ __('Push .env to server') }}</span>
                                        <span wire:loading wire:target="pushEnvToServer">{{ __('Pushing...') }}</span>
                                    </button>
                                @endif
                            </div>
                        </section>
                    @elseif ($section === 'webhooks')
                        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Deploy webhook') }}</h2>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Configure the site-specific deploy endpoint and its signature requirements here.') }}</p>
                            </div>

                            <p class="rounded-xl bg-brand-sand/30 p-3 font-mono text-sm break-all text-brand-ink">{{ $deployHookUrl }}</p>

                            @if ($revealed_webhook_secret)
                                <div>
                                    <p class="text-sm font-medium text-amber-800">{{ __('Copy your new secret now:') }}</p>
                                    <pre class="mt-2 overflow-x-auto rounded-xl bg-slate-900 p-3 text-xs text-amber-200">{{ $revealed_webhook_secret }}</pre>
                                </div>
                            @else
                                <p class="text-sm text-brand-moss">{{ __('Secret is stored encrypted. Rotate to see a new one.') }}</p>
                            @endif

                            <button type="button" wire:click="regenerateWebhookSecret" class="text-sm font-medium text-brand-ink underline">{{ __('Rotate webhook secret') }}</button>

                            <form wire:submit="saveWebhookSecurity" class="space-y-3 border-t border-brand-ink/10 pt-4">
                                <x-input-label for="webhook_allowed_ips_text" value="Optional IP allow list (one IPv4/IPv6 or IPv4 CIDR per line)" />
                                <textarea id="webhook_allowed_ips_text" wire:model="webhook_allowed_ips_text" rows="4" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="203.0.113.10&#10;192.0.2.0/24"></textarea>
                                <x-input-error :messages="$errors->get('webhook_allowed_ips_text')" class="mt-1" />
                                <x-primary-button type="submit">{{ __('Save allow list') }}</x-primary-button>
                            </form>
                        </section>
                    @elseif ($section === 'danger')
                        <section class="rounded-2xl border border-red-200 bg-white p-6 shadow-sm sm:p-8 space-y-4">
                            <div>
                                <h2 class="text-lg font-semibold text-red-900">{{ __('Danger zone') }}</h2>
                                <p class="mt-1 text-sm text-red-800">{{ __('Delete the site from Dply. This stays on its own tab so destructive actions are separated from normal site configuration.') }}</p>
                            </div>

                            @can('delete', $site)
                                <button type="button" wire:click="openConfirmActionModal('deleteSite', [], @js(__('Delete site')), @js(__('Delete this site from Dply? A background job removes Nginx vhost, optional releases/repo/cert, supervisor rows tied to this site, deploy SSH key, and re-syncs server crontab.')), @js(__('Delete site')), true)" class="rounded-xl border border-red-300 bg-red-50 px-4 py-2.5 text-sm font-medium text-red-800 hover:bg-red-100">
                                    {{ __('Delete site') }}
                                </button>
                            @endcan
                        </section>
                    @endif
                </div>
            </main>
        </div>
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
