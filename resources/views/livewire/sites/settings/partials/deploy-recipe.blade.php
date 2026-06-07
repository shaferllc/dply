<section class="dply-card overflow-hidden">
    @include('livewire.sites.partials.promote-cutover-panel', ['site' => $site])
    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-cloud-arrow-up class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Repository & recipe') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Repository source, rollout strategy, pipeline steps, and hooks — the deploy recipe top to bottom. Deploy execution itself lives on the site overview.') }}</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <a href="{{ route('sites.deployments.index', [$server, $site]) }}" wire:navigate class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('Deployments') }}</a>
            <span class="text-brand-mist" aria-hidden="true">·</span>
            <a href="{{ route('sites.repository', ['server' => $server, 'site' => $site, 'repo_tab' => 'commits']) }}" wire:navigate class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('Commits') }}</a>
            <span class="text-brand-mist" aria-hidden="true">·</span>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'logs']) }}" wire:navigate class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('Site logs') }}</a>
            <span class="text-brand-mist" aria-hidden="true">·</span>
            <a href="{{ route('servers.logs', $server) }}" wire:navigate class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('Server logs') }}</a>
        </div>
    </div>

    <div class="space-y-6 p-6 sm:p-8">

    <form wire:submit="saveGit" class="space-y-4">
        @if ($functionsHost)
            <div>
                <x-input-label for="functions_repo_source" value="Repository source" />
                <select id="functions_repo_source" wire:model.live="functions_repo_source" class="mt-1 block w-full rounded-md border-brand-ink/15 shadow-sm text-sm">
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
                        <select id="functions_source_control_account_id" wire:model.live="functions_source_control_account_id" class="mt-1 block w-full rounded-md border-brand-ink/15 shadow-sm text-sm">
                            <option value="">{{ __('Select an account') }}</option>
                            @foreach ($linkedSourceControlAccounts as $account)
                                <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('functions_source_control_account_id')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="functions_repository_selection" value="Repository" />
                        <select id="functions_repository_selection" wire:model.live="functions_repository_selection" class="mt-1 block w-full rounded-md border-brand-ink/15 shadow-sm text-sm">
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
            <div>
                <x-input-label for="post_deploy_command" value="Deploy command" />
                <textarea id="post_deploy_command" wire:model="post_deploy_command" rows="3" class="w-full rounded-md border-brand-ink/15 shadow-sm font-mono text-sm" placeholder="php artisan migrate --force"></textarea>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Runs in the build environment after dependencies install and the environment is prepared, before the function is packaged — use it for migrations and cache warming. A non-zero exit aborts the deploy.') }}</p>
                <x-input-error :messages="$errors->get('post_deploy_command')" class="mt-1" />
            </div>
        @else
            <div>
                <x-input-label for="post_deploy_command" value="Post-deploy command" />
                <textarea id="post_deploy_command" wire:model="post_deploy_command" rows="3" class="w-full rounded-md border-brand-ink/15 shadow-sm font-mono text-sm" placeholder="composer install --no-dev && php artisan migrate --force"></textarea>
            </div>
        @endif

        @if ($functionsHost && $functionsDetection !== [])
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-semibold text-brand-ink">{{ __('Detected') }}</span>
                        <span class="text-brand-moss">{{ str((string) ($functionsDetection['framework'] ?? 'unknown'))->replace('_', ' ')->title() }} · {{ str((string) ($functionsDetection['language'] ?? 'unknown'))->replace('_', ' ')->title() }}</span>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss ring-1 ring-brand-ink/10">
                        {{ strtoupper((string) ($functionsDetection['confidence'] ?? 'low')) }}
                    </span>
                </div>
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
                            <x-input-label for="functions_runtime" value="Serverless runtime" />
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
                            <textarea id="functions_build_command" wire:model="functions_build_command" rows="3" class="w-full rounded-md border-brand-ink/15 shadow-sm font-mono text-sm" placeholder="npm install && npm run build"></textarea>
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

    @if (! $functionsHost && $site->git_deploy_key_public)
        <div>
            <p class="text-sm text-brand-moss">{{ __('Public key (add to your Git provider deploy keys):') }}</p>
            <pre class="mt-2 overflow-x-auto rounded-xl bg-brand-ink p-3 text-xs text-green-400">{{ $site->git_deploy_key_public }}</pre>
        </div>
    @endif

    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-sky-50 text-sky-700 ring-sky-200">
                <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Stages') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Pipeline') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Lay out the deploy as pre, main, and post stages so custom steps stay visible around the core checkout and activation flow.') }}</p>
            </div>
        </div>
        <div class="space-y-4 p-6 sm:p-8">

        <div class="grid gap-4 xl:grid-cols-3">
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-4">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Pre-deploy script') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Ordered pipeline steps run after code is cloned and before the main activation flow completes.') }}</p>
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Current steps') }}</p>
                @if ($site->deploySteps->isNotEmpty())
                    <ol class="mt-2 space-y-2 text-sm">
                        @foreach ($site->deploySteps->sortBy('sort_order') as $step)
                            <li class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $step->phaseBadgeClass() }}">{{ $step->phase ?? 'build' }}</span>
                                        <span class="font-mono text-xs text-brand-ink">{{ $step->step_type }}</span>
                                        <span class="text-xs text-brand-moss">· {{ (int) ($step->timeout_seconds ?? 900) }}s</span>
                                        @if ($step->custom_command)
                                            <span class="text-brand-moss">— {{ \Illuminate\Support\Str::limit($step->custom_command, 60) }}</span>
                                        @endif
                                    </span>
                                    <span class="flex gap-3 text-xs">
                                        <button type="button" wire:click="openEditPipelineStep('{{ $step->id }}')" class="text-brand-forest hover:underline">{{ __('Edit') }}</button>
                                        <button type="button" wire:click="moveDeployStepUp('{{ $step->id }}')" class="text-brand-moss hover:underline">{{ __('Up') }}</button>
                                        <button type="button" wire:click="moveDeployStepDown('{{ $step->id }}')" class="text-brand-moss hover:underline">{{ __('Down') }}</button>
                                        <button type="button" wire:click="deleteDeployPipelineStep('{{ $step->id }}')" class="text-red-700 hover:underline">{{ __('Remove') }}</button>
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="mt-2 text-sm text-brand-moss">{{ __('No extra pre-deploy steps yet.') }}</p>
                @endif
            </div>

            <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-4">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Main deploy script') }}</p>
                <div class="mt-3 space-y-2 text-sm text-brand-moss">
                    <p>{{ __('1. Prepare deploy directory / release target') }}</p>
                    <p>{{ __('2. Clone or update the configured branch') }}</p>
                    <p>{{ __('3. Run ordered pipeline steps') }}</p>
                    <p>{{ __('4. Activate the release when atomic deploys are enabled') }}</p>
                </div>
            </div>

            <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-4">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Post-deploy script') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Legacy post-deploy command runs after the pipeline. Keep this empty if the ordered steps already express everything.') }}</p>
                <pre class="mt-3 overflow-x-auto rounded-xl bg-brand-ink p-3 text-xs text-emerald-100">{{ $post_deploy_command !== '' ? $post_deploy_command : __('No post-deploy command configured.') }}</pre>
            </div>
        </div>

        @if (! $functionsHost)
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-4">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Add or edit a pipeline step') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Opens a modal to configure type, phase, timeout, and command.') }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-secondary-button type="button" wire:click="openAddPipelineStepForm">{{ __('Add step') }}</x-secondary-button>
                    <x-secondary-button type="button" wire:click="openAddPipelineStepForm('custom', 'build')">{{ __('Custom command') }}</x-secondary-button>
                </div>
            </div>
            @include('livewire.sites.partials.pipeline._pipeline-modals')
        @endif
        </div>
    </section>

    @if ($site->usesDockerRuntime())
        @php
            $dockerRuntime = is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
        @endphp
        <details class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4">
            <summary class="cursor-pointer list-none text-sm font-semibold text-brand-ink">{{ __('Runtime target') }} <span class="font-normal text-brand-moss">— {{ __('Compose / Dockerfile (`docker compose up -d --build` on deploy)') }}</span></summary>
            <div class="mt-4 grid gap-4 xl:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Compose file') }}</p>
                    <pre class="mt-2 max-h-80 overflow-auto rounded-xl bg-brand-ink p-3 text-xs text-sky-100">{{ $dockerRuntime['compose_yaml'] ?? __('Not generated yet') }}</pre>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Managed Dockerfile') }}</p>
                    <pre class="mt-2 max-h-80 overflow-auto rounded-xl bg-brand-ink p-3 text-xs text-emerald-100">{{ $dockerRuntime['dockerfile'] ?? __('Not generated yet') }}</pre>
                </div>
            </div>
        </details>
    @endif

    @if ($site->usesKubernetesRuntime())
        @php
            $kubernetesRuntime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        @endphp
        <details class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4">
            <summary class="cursor-pointer list-none text-sm font-semibold text-brand-ink">{{ __('Runtime target') }} <span class="font-normal text-brand-moss">— {{ __('Manifest for namespace') }} <code>{{ $kubernetesRuntime['namespace'] ?? 'default' }}</code></span></summary>
            <div class="mt-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Manifest') }}</p>
                <pre class="mt-2 max-h-96 overflow-auto rounded-xl bg-brand-ink p-3 text-xs text-violet-100">{{ $kubernetesRuntime['manifest_yaml'] ?? __('Not generated yet') }}</pre>
            </div>
        </details>
    @endif

    <section class="dply-card overflow-hidden">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-8">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-700 ring-amber-200">
                    <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Hooks') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Pipeline hooks') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Shell scripts, HTTP webhooks, and notification channels live on the Pipeline timeline — before clone, after any build step, or after activate.') }}
                    </p>
                </div>
            </div>
            <a
                href="{{ route('sites.pipeline', [$server, $site, 'tab' => 'steps']) }}"
                wire:navigate
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-brand-cream hover:bg-brand-forest"
            >
                <x-heroicon-m-wrench-screwdriver class="h-4 w-4" />
                {{ __('Edit on Pipeline') }}
            </a>
        </div>
        <div class="p-6 sm:p-8">
            @php $hookCount = $site->deployHooks->count(); @endphp
            <p class="text-sm text-brand-moss">
                @if ($hookCount > 0)
                    {{ trans_choice(':count hook on the active deploy pipeline|:count hooks on the active deploy pipeline', $hookCount, ['count' => $hookCount]) }}
                @else
                    {{ __('No hooks on the active pipeline yet.') }}
                @endif
            </p>
        </div>
    </section>

    {{-- Zero downtime + post-activate health check + the broader rollout
         form (releases, env, scheduler, supervisor, extra Nginx) sit
         after Pipeline so the page reads chronologically:
         Source → Build → Pipeline → Activate → Rollout. --}}
    @if (! $functionsHost)
        <form wire:submit="saveZeroDowntimeDeployment" class="dply-card overflow-hidden">
            <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 p-6 lg:border-b-0 lg:border-r">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sage/15 text-brand-forest ring-brand-sage/25">
                            <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Activate') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Zero downtime deployment') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('When enabled, each deploy goes to a new release directory, then traffic switches to it in one step so the app stays up during builds. Disable to run simple git-based deploys in the deploy path.') }}</p>
                        </div>
                    </div>
                </div>

                <div class="space-y-3 p-6 sm:p-8">
                    <label class="flex items-center gap-3">
                        <input type="checkbox" wire:model="zero_downtime_enabled" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                        <span class="text-sm font-semibold text-brand-ink">{{ __('Enable zero-downtime rollout') }}</span>
                    </label>
                    <x-input-error :messages="$errors->get('zero_downtime_enabled')" />
                </div>
            </div>

            <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>

        @if (ephemeral_deploy_credentials_active($site->organization))
            <form wire:submit="saveEphemeralDeployCredentials" class="dply-card overflow-hidden">
                <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                    <div class="border-b border-brand-ink/10 bg-brand-cream/40 p-6 lg:border-b-0 lg:border-r">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sage/15 text-brand-forest ring-brand-sage/25">
                                <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Security') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Ephemeral deploy credentials') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Issue a one-time SSH key for each deploy, sync it to the server for the rollout, then revoke it when the deploy finishes — success or failure.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3 p-6 sm:p-8">
                        <label class="flex items-center gap-3">
                            <input type="checkbox" wire:model="ephemeral_deploy_credentials_enabled" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                            <span class="text-sm font-semibold text-brand-ink">{{ __('Use ephemeral SSH credentials for deploys') }}</span>
                        </label>
                        <x-input-error :messages="$errors->get('ephemeral_deploy_credentials_enabled')" />
                        <p class="text-sm text-brand-moss">{{ __('Each deployment gets its own ed25519 key with a fingerprint recorded in the audit log. Your server’s operational SSH key is still used to install and remove deploy keys.') }}</p>
                    </div>
                </div>

                <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
                    <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                </div>
            </form>
        @endif

        <form wire:submit="saveDeploymentSettings" class="dply-card space-y-5 p-6 sm:p-8">
            @if ($zero_downtime_enabled)
                <div class="space-y-4 rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-4">
                    <div>
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('After deploy verification') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Optional HTTP(S) check from the server to a local address with your primary hostname as the Host header after the new release is active. Defaults to http://127.0.0.1. Requires a primary domain and a route that returns the expected status (for example Laravel /up).') }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-medium text-brand-ink">
                        <input type="checkbox" wire:model="deploy_health_enabled" class="rounded border-brand-ink/15 text-brand-forest shadow-sm focus:ring-brand-forest">
                        {{ __('Run health check after each atomic deploy') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm font-medium text-brand-ink">
                        <input type="checkbox" wire:model="deploy_health_auto_rollback" class="rounded border-brand-ink/15 text-brand-forest shadow-sm focus:ring-brand-forest" @disabled(! $deploy_health_enabled)>
                        {{ __('Automatically point current back at the previous release if the check fails') }}
                    </label>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <x-input-label for="deploy_health_scheme" value="{{ __('URL scheme') }}" />
                            <select id="deploy_health_scheme" wire:model="deploy_health_scheme" class="mt-1 block w-full rounded-md border-brand-ink/15 shadow-sm text-sm" @disabled(! $deploy_health_enabled)>
                                <option value="http">http</option>
                                <option value="https">https</option>
                            </select>
                            <x-input-error :messages="$errors->get('deploy_health_scheme')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_host" value="{{ __('Target host') }}" />
                            <x-text-input id="deploy_health_host" wire:model="deploy_health_host" class="font-mono text-sm" placeholder="127.0.0.1" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_host')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_port" value="{{ __('Target port (optional)') }}" />
                            <x-text-input id="deploy_health_port" type="number" wire:model="deploy_health_port" class="font-mono text-sm" placeholder="80 / 443 / custom" min="1" max="65535" :disabled="! $deploy_health_enabled" />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Leave empty to use the default port for the scheme (80 or 443).') }}</p>
                            <x-input-error :messages="$errors->get('deploy_health_port')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_path" value="{{ __('Health path') }}" />
                            <x-text-input id="deploy_health_path" wire:model="deploy_health_path" class="font-mono text-sm" placeholder="/up" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_path')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_expect_status" value="{{ __('Expected HTTP status') }}" />
                            <x-text-input id="deploy_health_expect_status" type="number" wire:model="deploy_health_expect_status" class="w-24" min="100" max="599" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_expect_status')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_attempts" value="{{ __('Attempts') }}" />
                            <x-text-input id="deploy_health_attempts" type="number" wire:model="deploy_health_attempts" class="w-24" min="1" max="30" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_attempts')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_delay_ms" value="{{ __('Delay between attempts (ms)') }}" />
                            <x-text-input id="deploy_health_delay_ms" type="number" wire:model="deploy_health_delay_ms" class="w-28" min="0" max="10000" step="50" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_delay_ms')" class="mt-1" />
                        </div>
                    </div>
                </div>
            @endif

            @php
                // Stack-specific post-activate controls (see _tab-rollout): hide the
                // Laravel scheduler cron on non-Laravel/non-PHP stacks, the Supervisor
                // restart when the site has no Supervisor programs, and the Nginx
                // snippet on non-Nginx engines. Header copy names only what's shown.
                $drIsNginx = $site->webserver() === 'nginx';
                $drShowScheduler = $site->supportsLaravelScheduler();
                $drShowSupervisor = $site->hasRestartableSupervisorPrograms();
                $drParts = [__('releases to keep'), __('environment group')];
                if ($drShowScheduler) { $drParts[] = __('scheduler'); }
                if ($drShowSupervisor) { $drParts[] = __('Supervisor'); }
                if ($drIsNginx) { $drParts[] = __('extra Nginx directives'); }
                $drSummary = ucfirst(collect($drParts)->join(', ', __(', and '))).'. '.__('Runtime ports and users live on Runtime / Stack / System user.');
            @endphp
            <div>
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Rollout and web server') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ $drSummary }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div>
                    <x-input-label for="releases_to_keep" value="Releases to keep" />
                    <x-text-input id="releases_to_keep" type="number" wire:model="releases_to_keep" class="mt-1 w-28" min="1" max="50" />
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Applies when zero downtime deployment is enabled.') }}</p>
                </div>
                <div>
                    <x-input-label for="deployment_environment" value="Environment group" />
                    <x-text-input id="deployment_environment" wire:model="deployment_environment" class="mt-1 block w-full text-sm" />
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Used when resolving key/value environment variables for deploys.') }}</p>
                </div>
            </div>

            @if ($drShowScheduler || $drShowSupervisor || $drIsNginx)
            <div class="grid gap-3">
                @if ($drShowScheduler)
                <div>
                    <label class="flex items-center gap-2 text-sm text-brand-ink">
                        <input type="checkbox" wire:model="laravel_scheduler" class="rounded border-brand-ink/15">
                        {{ $site->runtimeSchedulerRolloutFormLabel() }}
                    </label>
                    @if ($site->runtimeSchedulerCheckboxHelp())
                        <p class="mt-1 pl-6 text-xs text-brand-moss">{{ $site->runtimeSchedulerCheckboxHelp() }}</p>
                    @endif
                </div>
                @endif
                @if ($drShowSupervisor)
                <label class="flex items-center gap-2 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="restart_supervisor_programs_after_deploy" class="rounded border-brand-ink/15">
                    {{ __('Restart Supervisor programs after successful deploy') }}
                </label>
                @endif
                @if ($drIsNginx)
                <div>
                    <x-input-label for="nginx_extra_raw" value="Extra Nginx inside server block (advanced)" />
                    <textarea id="nginx_extra_raw" wire:model="nginx_extra_raw" rows="4" class="w-full rounded-md border-brand-ink/15 shadow-sm font-mono text-xs" placeholder="# location /foo { ... }"></textarea>
                </div>
                @endif
            </div>
            @endif

            <x-primary-button type="submit">{{ __('Save rollout settings') }}</x-primary-button>
        </form>
    @endif

    <details class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
        <summary class="cursor-pointer list-none">
            <h3 class="inline text-base font-semibold text-brand-ink">{{ __('Deploy script variables') }}</h3>
            <span class="ml-2 text-sm text-brand-moss">— {{ __('placeholders for custom steps, post-deploy commands, and hook scripts') }}</span>
        </summary>
        <dl class="mt-4 grid gap-3 md:grid-cols-2">
            @foreach ($deployVariableReference as $token => $description)
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-4">
                    <dt class="font-mono text-sm text-brand-ink">{{ $token }}</dt>
                    <dd class="mt-2 text-sm text-brand-moss">{{ $description }}</dd>
                </div>
            @endforeach
        </dl>
    </details>
    </div>
</section>

<x-cli-snippet
    class="mt-6"
    :summary="__('dply CLI (from your laptop)')"
    :intro="__('Run `dply link --byo :id` once in your repo root, commit `.dply/site.json`, then deploy with bare `dply deploy`. Re-login with `dply auth refresh` if scopes are missing.', ['id' => $site->id])"
    :commands="[
        ['label' => __('Link this repo'), 'command' => 'dply link --byo '.$site->id],
        ['label' => __('Deploy (linked repo)'), 'command' => 'dply deploy --follow'],
        ['label' => __('Deploy this site'), 'command' => 'dply site deploy --site '.$site->id.' --follow'],
        ['label' => __('Tail deploy logs'), 'command' => 'dply site logs --site '.$site->id.' --follow'],
        ['label' => __('Site status'), 'command' => 'dply site status --site '.$site->id],
    ]"
/>

<x-cli-snippet
    :summary="__('Artisan (on the server)')"
    :commands="[
    ['label' => __('Trigger deploy'), 'command' => 'dply sites:deploy '.$site->slug],
    ['label' => __('Abort running deploy'), 'command' => 'dply sites:deploy:abort '.$site->slug],
    ['label' => __('Run a single phase'), 'command' => 'dply sites:deploy:phase '.$site->slug.' build'],
    ['label' => __('Recent deploy history'), 'command' => 'dply sites:deployments '.$site->slug],
    ['label' => __('Inspect a deploy'), 'command' => 'dply sites:deployment DEPLOYMENT_ID --output'],
]" />
