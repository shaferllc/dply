<div>
    @php($functionsHost = $server->hostCapabilities()->supportsFunctionDeploy())
    <div class="border-b border-slate-200 bg-white">
        <div class="dply-page-shell py-8">
            <x-page-header
                :title="__('Create site')"
                :description="$functionsHost ? __('Set up the domain, runtime, and artifact details for a new site on :server. This host deploys through a serverless target instead of an SSH machine.', ['server' => $server->name]) : __('Set up the domain, stack, and deploy paths for a new site on :server. Dply will wire a temporary testing hostname during provisioning so you can verify the install before customer DNS is switched.', ['server' => $server->name])"
                doc-route="docs.index"
                flush
            >
                <x-slot name="actions">
                    <a href="{{ route('servers.sites', $server) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('Cancel') }}</a>
                </x-slot>
            </x-page-header>
        </div>
    </div>

    <div class="py-10">
        <div class="dply-page-shell">
            <form wire:submit="store" class="space-y-10">
                <section aria-labelledby="server-context-heading">
                    <h2 id="server-context-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('1. Confirm server context') }}</h2>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-slate-900">{{ $server->name }}</h3>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ $functionsHost
                                        ? __('This site will be created on the selected Functions host and use its package-based runtime path.')
                                        : __('This site will be created on the selected server and use its current web stack.') }}
                                </p>
                            </div>
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-600">
                                {{ __('Server ready') }}
                            </span>
                        </div>

                        <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Provider') }}</dt>
                                <dd class="mt-2 text-sm font-medium text-slate-900">{{ $server->providerDisplayLabel() }}</dd>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Health') }}</dt>
                                <dd class="mt-2 text-sm font-medium text-slate-900">
                                    @if ($server->health_status === 'reachable')
                                        {{ __('Reachable') }}
                                    @elseif ($server->health_status === 'unreachable')
                                        {{ __('Unreachable') }}
                                    @else
                                        {{ __('Unknown') }}
                                    @endif
                                </dd>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('IP address') }}</dt>
                                <dd class="mt-2 font-mono text-sm text-slate-900">{{ $functionsHost ? __('Not applicable') : ($server->ip_address ?? '—') }}</dd>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Existing sites') }}</dt>
                                <dd class="mt-2 text-sm font-medium text-slate-900">{{ number_format($server->sites_count) }}</dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <section aria-labelledby="site-basics-heading">
                    <h2 id="site-basics-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('2. Site basics') }}</h2>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 space-y-5">
                        <div>
                            <x-input-label for="name" :value="__('Site name')" />
                            <x-text-input id="name" wire:model="form.name" class="mt-1 block w-full" required autofocus autocomplete="off" />
                            <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="primary_hostname" :value="__('Primary domain')" />
                            <x-text-input id="primary_hostname" wire:model.live.debounce.300ms="form.primary_hostname" placeholder="app.example.com" class="mt-1 block w-full font-mono text-sm" required autocomplete="off" />
                            <p class="mt-2 text-sm text-slate-600">
                                {{ $functionsHost
                                    ? __('Use the real customer-facing domain here. Dply tracks the domain, repository, and runtime metadata for the first publish.')
                                    : __('Use the real customer-facing domain here. Dply can still provision a temporary testing hostname on one of your owned zones while you finish setup.') }}
                            </p>
                            <x-input-error :messages="$errors->get('form.primary_hostname')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="type" :value="__('Stack')" />
                            <select id="type" wire:model.live="form.type" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                <option value="php">{{ __('PHP (PHP-FPM + Nginx)') }}</option>
                                <option value="static">{{ __('Static files') }}</option>
                                <option value="node">{{ __('Node (Nginx → reverse proxy)') }}</option>
                            </select>
                            <p class="mt-2 text-sm text-slate-600">
                                @if ($form->type === 'php')
                                    {{ __('Best for Laravel, WordPress, and other PHP apps that serve from a public web root.') }}
                                @elseif ($form->type === 'static')
                                    {{ __('Best for HTML, CSS, JS, or a prebuilt frontend that should be served directly from disk.') }}
                                @else
                                    {{ __('Best for apps that run their own process and should be proxied by Nginx.') }}
                                @endif
                            </p>
                        </div>
                    </div>
                </section>

                @if (! $functionsHost)
                <section aria-labelledby="auto-detect-heading">
                    <h2 id="auto-detect-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('Auto-detect (optional)') }}</h2>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 space-y-5">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">{{ __('Detect runtime from a repository URL') }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Paste a git URL and dply will inspect the repo to suggest the runtime, version, build command, start command, and any worker / scheduler processes. You can override anything before saving.') }}</p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-[1fr,180px,auto] sm:items-end">
                            <div>
                                <x-input-label for="git_repository_url" :value="__('Repository URL')" />
                                <x-text-input id="git_repository_url" wire:model="form.git_repository_url" placeholder="https://github.com/your/app.git" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                            </div>
                            <div>
                                <x-input-label for="git_branch" :value="__('Branch')" />
                                <x-text-input id="git_branch" wire:model="form.git_branch" placeholder="main" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                            </div>
                            <button type="button" wire:click="detectFromRepository" wire:loading.attr="disabled" wire:target="detectFromRepository" class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-ink/90 disabled:opacity-50">
                                <span wire:loading.remove wire:target="detectFromRepository">{{ __('Detect') }}</span>
                                <span wire:loading wire:target="detectFromRepository">{{ __('Detecting…') }}</span>
                            </button>
                        </div>

                        @if (! empty($detectedPlan['error']))
                            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                                <p class="font-medium">{{ __('Could not clone the repository.') }}</p>
                                <p class="mt-1 font-mono text-xs">{{ $detectedPlan['error'] }}</p>
                            </div>
                        @elseif (! empty($detectedPlan['no_match']))
                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                <p class="font-medium">{{ __('No runtime detected.') }}</p>
                                <p class="mt-1">{{ __('No dply.yaml manifest and no recognized runtime signals (composer.json, package.json, requirements.txt, Gemfile, go.mod, index.html, etc.) at the repo root.') }}</p>
                            </div>
                        @elseif (! empty($detectedPlan['runtime']))
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 text-sm text-emerald-950 space-y-3">
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="inline-flex items-center gap-2 rounded-full bg-white/70 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-emerald-900">
                                        {{ $detectedPlan['runtime'] }}
                                        @if (! empty($detectedPlan['framework']) && $detectedPlan['framework'] !== $detectedPlan['runtime'])
                                            · {{ $detectedPlan['framework'] }}
                                        @endif
                                    </span>
                                    @if (! empty($detectedPlan['version']))
                                        <span class="inline-flex items-center gap-1 rounded-full bg-white/70 px-3 py-1 font-mono text-xs text-emerald-900">{{ $detectedPlan['version'] }}</span>
                                    @endif
                                    <span class="inline-flex items-center gap-1 rounded-full bg-white/40 px-3 py-1 text-[11px] uppercase tracking-[0.16em] text-emerald-900/80">{{ $detectedPlan['confidence'] }} confidence</span>
                                    @if (! empty($detectedPlan['has_manifest']))
                                        <span class="inline-flex items-center gap-1 rounded-full bg-white/40 px-3 py-1 text-[11px] uppercase tracking-[0.16em] text-emerald-900/80">{{ __('dply.yaml present') }}</span>
                                    @endif
                                </div>

                                <dl class="grid gap-3 sm:grid-cols-2">
                                    @if (! empty($detectedPlan['build_command']))
                                        <div>
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-emerald-800">{{ __('Build command') }}</dt>
                                            <dd class="mt-1 font-mono text-xs text-emerald-950 break-all">{{ $detectedPlan['build_command'] }}</dd>
                                        </div>
                                    @endif
                                    @if (! empty($detectedPlan['start_command']))
                                        <div>
                                            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-emerald-800">{{ __('Start command') }}</dt>
                                            <dd class="mt-1 font-mono text-xs text-emerald-950 break-all">{{ $detectedPlan['start_command'] }}</dd>
                                        </div>
                                    @endif
                                </dl>

                                @if (! empty($detectedPlan['processes']))
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-emerald-800">{{ __('Suggested processes') }}</p>
                                        <ul class="mt-1 space-y-1 text-xs">
                                            @foreach ($detectedPlan['processes'] as $process)
                                                <li class="flex items-start gap-2">
                                                    <span class="mt-0.5 inline-flex shrink-0 items-center rounded-full bg-white/70 px-2 py-0.5 font-semibold uppercase tracking-[0.12em] text-[10px] text-emerald-900">{{ $process['type'] }}</span>
                                                    <span><span class="font-semibold">{{ $process['name'] }}</span> — <span class="font-mono">{{ $process['command'] }}</span></span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                @if (! empty($detectedPlan['reasons']))
                                    <details class="text-xs text-emerald-900/80">
                                        <summary class="cursor-pointer font-semibold uppercase tracking-[0.16em]">{{ __('Detection details') }}</summary>
                                        <ul class="mt-2 space-y-1 pl-3 list-disc">
                                            @foreach ($detectedPlan['reasons'] as $reason)
                                                <li>{!! \Illuminate\Support\Str::of($reason)->replaceMatches('/`([^`]+)`/', '<code class="font-mono">$1</code>') !!}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif

                                @if (! empty($detectedPlan['warnings']))
                                    <ul class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 space-y-1">
                                        @foreach ($detectedPlan['warnings'] as $warning)
                                            <li>{{ $warning }}</li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($this->detectedRuntimeNeedsInstall)
                                    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                                        <p class="font-medium">{{ __('Heads up: this server hasn\'t pinned :runtime yet.', ['runtime' => ucfirst((string) $detectedPlan['runtime'])]) }}</p>
                                        <p class="mt-1 text-xs">{{ __('mise will install it on demand at deploy time, but you can preinstall now to keep the first deploy fast.') }}</p>
                                        <button
                                            type="button"
                                            wire:click="installDetectedRuntimeOnServer"
                                            wire:loading.attr="disabled"
                                            wire:target="installDetectedRuntimeOnServer"
                                            class="mt-3 inline-flex items-center justify-center rounded-lg bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-sky-800 disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="installDetectedRuntimeOnServer">{{ __('Install :runtime :version on this server', ['runtime' => ucfirst((string) $detectedPlan['runtime']), 'version' => $detectedPlan['version'] ?? '']) }}</span>
                                            <span wire:loading wire:target="installDetectedRuntimeOnServer">{{ __('Installing…') }}</span>
                                        </button>
                                    </div>
                                @endif

                                @if (! empty($runtimeInstallResult))
                                    <div class="rounded-xl border {{ ($runtimeInstallResult['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900' }} p-3 text-xs">
                                        {{ $runtimeInstallResult['message'] ?? '' }}
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </section>

                <section aria-labelledby="deploy-paths-heading">
                    <h2 id="deploy-paths-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('3. Deploy paths') }}</h2>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 space-y-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h3 class="text-base font-semibold text-slate-900">{{ __('Auto-generated paths') }}</h3>
                                <p class="mt-1 text-sm text-slate-600">{{ __('These defaults are derived from the primary domain and selected stack so new sites stay consistent across the server.') }}</p>
                            </div>
                            <label class="inline-flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm">
                                <input type="checkbox" wire:model.live="form.customize_paths" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500" />
                                {{ __('Customize paths') }}
                            </label>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Document root') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $form->document_root }}</dd>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Deploy path') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ $form->repository_path }}</dd>
                            </div>
                        </div>

                        <p class="text-sm text-slate-600">
                            @if ($form->type === 'php')
                                {{ __('PHP sites deploy into the app root and serve from the nested public directory by default.') }}
                            @else
                                {{ __('Static and Node sites use the deploy path directly unless you override it.') }}
                            @endif
                        </p>

                        @if ($form->customize_paths)
                            <div class="grid gap-5">
                                <div>
                                    <x-input-label for="document_root" :value="__('Document root (advanced override)')" />
                                    <x-text-input id="document_root" wire:model="form.document_root" class="mt-1 block w-full font-mono text-sm" required />
                                    <x-input-error :messages="$errors->get('form.document_root')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="repository_path" :value="__('Deploy path (advanced override)')" />
                                    <x-text-input id="repository_path" wire:model="form.repository_path" class="mt-1 block w-full font-mono text-sm" />
                                    <p class="mt-2 text-sm text-slate-600">{{ __('This is where deploys, git operations, and placeholder content will be managed for the site.') }}</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </section>
                @endif

                @if ($functionsHost)
                <section aria-labelledby="repo-build-heading">
                    <h2 id="repo-build-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('3. Repository and build') }}</h2>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 space-y-5">
                        <div>
                            <x-input-label for="functions_repo_source" :value="__('Repository source')" />
                            <select id="functions_repo_source" wire:model.live="form.functions_repo_source" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                @if (count($linkedSourceControlAccounts) > 0)
                                    <option value="provider">{{ __('Connected Git provider') }}</option>
                                @endif
                                <option value="manual">{{ __('Manual Git URL') }}</option>
                            </select>
                            <p class="mt-2 text-sm text-slate-600">{{ __('Choose a linked source-control account to pick a repo, or paste a Git URL directly.') }}</p>
                        </div>

                        @if ($form->functions_repo_source === 'provider' && count($linkedSourceControlAccounts) > 0)
                            <div class="grid gap-5 md:grid-cols-2">
                                <div>
                                    <x-input-label for="functions_source_control_account_id" :value="__('Connected account')" />
                                    <select id="functions_source_control_account_id" wire:model.live="form.functions_source_control_account_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                        <option value="">{{ __('Select an account') }}</option>
                                        @foreach ($linkedSourceControlAccounts as $account)
                                            <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('form.functions_source_control_account_id')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="functions_repository_selection" :value="__('Repository')" />
                                    <select id="functions_repository_selection" wire:model.live="form.functions_repository_selection" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                        <option value="">{{ __('Select a repository') }}</option>
                                        @foreach ($availableFunctionsRepositories as $repository)
                                            <option value="{{ $repository['url'] }}">{{ $repository['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-2 text-sm text-slate-600">{{ __('The default branch is filled automatically from the selected repository when available.') }}</p>
                                    <x-input-error :messages="$errors->get('form.functions_repository_selection')" class="mt-1" />
                                </div>
                            </div>
                        @endif

                        <div class="grid gap-5 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <x-input-label for="functions_repository_url" :value="__('Repository URL')" />
                                <x-text-input id="functions_repository_url" wire:model.blur="form.functions_repository_url" class="mt-1 block w-full font-mono text-sm" placeholder="https://github.com/org/repo.git" />
                                <p class="mt-2 text-sm text-slate-600">{{ __('Dply clones this repo on the queue worker, builds it, and packages the deployable output into a zip before publish.') }}</p>
                                <x-input-error :messages="$errors->get('form.functions_repository_url')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="functions_repository_branch" :value="__('Branch')" />
                                <x-text-input id="functions_repository_branch" wire:model.blur="form.functions_repository_branch" class="mt-1 block w-full font-mono text-sm" />
                                <x-input-error :messages="$errors->get('form.functions_repository_branch')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="functions_repository_subdirectory" :value="__('Repository subdirectory')" />
                                <x-text-input id="functions_repository_subdirectory" wire:model.blur="form.functions_repository_subdirectory" class="mt-1 block w-full font-mono text-sm" placeholder="apps/functions" />
                                <p class="mt-2 text-sm text-slate-600">{{ __('Optional. Use this when the deployable function code lives in a subdirectory of a monorepo.') }}</p>
                                <x-input-error :messages="$errors->get('form.functions_repository_subdirectory')" class="mt-1" />
                            </div>
                        </div>

                        @if ($functionsDetection !== [])
                            <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 space-y-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">{{ __('Detected setup') }}</p>
                                        <p class="mt-1 text-sm text-slate-600">
                                            {{ __('Dply inspected the selected repository and inferred a starting runtime/build setup. You can override it below if needed.') }}
                                        </p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-600 ring-1 ring-slate-200">
                                        {{ strtoupper((string) ($functionsDetection['confidence'] ?? 'low')) }}
                                    </span>
                                </div>
                                <dl class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Framework') }}</dt>
                                        <dd class="mt-1 text-sm font-medium text-slate-900">{{ str((string) ($functionsDetection['framework'] ?? 'unknown'))->replace('_', ' ')->title() }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Language') }}</dt>
                                        <dd class="mt-1 text-sm font-medium text-slate-900">{{ str((string) ($functionsDetection['language'] ?? 'unknown'))->replace('_', ' ')->title() }}</dd>
                                    </div>
                                </dl>
                                @if (count($functionsDetection['warnings'] ?? []) > 0)
                                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 space-y-2">
                                        @foreach (($functionsDetection['warnings'] ?? []) as $warning)
                                            <p>{{ $warning }}</p>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </section>
                @endif

                <section aria-labelledby="runtime-heading">
                    <h2 id="runtime-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('4. Runtime settings') }}</h2>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 space-y-5">
                        @if ($functionsHost)
                            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4 text-sm text-slate-600">
                                {{ __('Dply will generate and store the deploy zip automatically from your repo and build output. Runtime, entrypoint, and build settings are auto-detected first, then you can review them in Advanced settings if needed.') }}
                            </div>
                            <details class="rounded-2xl border border-slate-200 bg-white p-4" @if(($functionsDetection['unsupported_for_target'] ?? false) || (($functionsDetection['confidence'] ?? '') === 'low')) open @endif>
                                <summary class="cursor-pointer list-none text-sm font-semibold text-slate-900">{{ __('Advanced runtime overrides') }}</summary>
                                <div class="mt-4 grid gap-5 md:grid-cols-2">
                                    <div>
                                        <x-input-label for="functions_runtime" :value="__('Serverless runtime')" />
                                        <x-text-input id="functions_runtime" wire:model="form.functions_runtime" class="mt-1 block w-full font-mono text-sm" />
                                        <p class="mt-2 text-sm text-slate-600">{{ __('Example: `nodejs:18` for DigitalOcean Functions or `provided.al2023` for AWS Lambda/Bref.') }}</p>
                                        <x-input-error :messages="$errors->get('form.functions_runtime')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="functions_entrypoint" :value="__('HTTP entrypoint')" />
                                        <x-text-input id="functions_entrypoint" wire:model="form.functions_entrypoint" class="mt-1 block w-full font-mono text-sm" />
                                        <p class="mt-2 text-sm text-slate-600">{{ __('This maps to the default action entrypoint inside the deployed zip artifact.') }}</p>
                                        <x-input-error :messages="$errors->get('form.functions_entrypoint')" class="mt-1" />
                                    </div>
                                    <div class="md:col-span-2">
                                        <x-input-label for="functions_build_command" :value="__('Build command')" />
                                        <textarea id="functions_build_command" wire:model="form.functions_build_command" rows="3" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500" placeholder="npm install && npm run build"></textarea>
                                        <p class="mt-2 text-sm text-slate-600">{{ __('This runs inside the checked-out repo before Dply packages the deployable output.') }}</p>
                                        <x-input-error :messages="$errors->get('form.functions_build_command')" class="mt-1" />
                                    </div>
                                    <div class="md:col-span-2">
                                        <x-input-label for="functions_artifact_output_path" :value="__('Build output path')" />
                                        <x-text-input id="functions_artifact_output_path" wire:model="form.functions_artifact_output_path" class="mt-1 block w-full font-mono text-sm" placeholder="dist" />
                                        <p class="mt-2 text-sm text-slate-600">{{ __('Path inside the repo checkout that should be packaged into the final zip. This can point to a directory or a generated zip file.') }}</p>
                                        <x-input-error :messages="$errors->get('form.functions_artifact_output_path')" class="mt-1" />
                                    </div>
                                </div>
                            </details>
                        @endif

                        @if ($form->type === 'php')
                            <div>
                                <x-input-label for="php_version" :value="__('PHP-FPM version')" />
                                <select id="php_version" wire:model="form.php_version" class="mt-1 block w-full max-w-xs rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                    <option value="">{{ __('Select a PHP version') }}</option>
                                    @foreach ($phpVersions as $version)
                                        <option value="{{ $version['id'] }}">{{ $version['label'] }}</option>
                                    @endforeach
                                </select>
                                @if ($phpVersions !== [] && ! $functionsHost)
                                    <p class="mt-2 text-sm text-slate-600">{{ __('This maps to the Ubuntu socket path at') }} <code class="rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-800">/run/php/php{version}-fpm.sock</code>.</p>
                                @elseif (! $functionsHost)
                                    <p class="mt-2 text-sm text-slate-600">{{ __('No supported PHP versions are currently installed on this server. Install one from the server PHP workspace before creating a PHP site.') }}</p>
                                @else
                                    <p class="mt-2 text-sm text-slate-600">{{ __('Functions hosts do not inspect machine PHP versions. The deployed artifact must provide the correct runtime entrypoint for this target.') }}</p>
                                @endif
                                <x-input-error :messages="$errors->get('form.php_version')" class="mt-1" />
                            </div>
                        @endif

                        @if ($form->type === 'node')
                            <div>
                                <x-input-label for="app_port" :value="__('App listens on (localhost)')" />
                                <x-text-input id="app_port" type="number" wire:model="form.app_port" class="mt-1 block w-full max-w-[8rem]" />
                                <p class="mt-2 text-sm text-slate-600">{{ __('Nginx will proxy requests to this port on the server.') }}</p>
                            </div>
                        @endif

                        @if ($form->type === 'static')
                            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4 text-sm text-slate-600">
                                {{ __('Static sites ship with a placeholder page first so the temporary testing hostname can return a healthy response before your real assets are deployed.') }}
                            </div>
                        @endif
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
                    <a
                        href="{{ route('servers.sites', $server) }}"
                        wire:navigate
                        class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                    >
                        {{ __('Cancel') }}
                    </a>
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800"
                    >
                        {{ __('Create site') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
