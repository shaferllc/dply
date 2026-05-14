<div>
    <div class="border-b border-slate-200 bg-white">
        <div class="dply-page-shell py-8">
            <x-page-header
                :eyebrow="__('Repo-first containers')"
                :title="__('Launch a container target from a repo')"
                :description="__('Inspect the repo first, review the inferred runtime, then choose a remote Docker host or Kubernetes cluster — DigitalOcean or AWS.')"
                doc-route="docs.index"
                flush
            >
                <x-slot name="actions">
                    <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('Back to Launch setup') }}</a>
                </x-slot>
            </x-page-header>
        </div>
    </div>

    <div class="py-10">
        <div class="dply-page-shell space-y-6">
            <section data-testid="oss-presets" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">{{ __('Try an open-source preset') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('One-click fill the repo URL with a real OSS project. You can edit the fields before inspecting.') }}</p>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($ossPresets as $preset)
                        <button type="button" wire:click="applyPreset('{{ $preset['id'] }}')" class="rounded-2xl border border-slate-200 bg-slate-50/70 p-3 text-left transition-colors hover:border-sky-300 hover:bg-sky-50">
                            <p class="text-sm font-semibold text-slate-900">{{ $preset['label'] }}</p>
                            <p class="mt-1 text-xs text-slate-600">{{ $preset['description'] }}</p>
                            <p class="mt-2 truncate font-mono text-[11px] text-slate-500">{{ \Illuminate\Support\Str::after($preset['url'], 'https://') }}</p>
                        </button>
                    @endforeach
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="grid gap-6 md:grid-cols-2">
                    <div>
                        <x-input-label for="repo_source" :value="__('Repo source')" />
                        <select id="repo_source" wire:model.live="repo_source" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                            <option value="manual">{{ __('Manual Git URL') }}</option>
                            <option value="provider" @disabled($linkedSourceControlAccounts === [])>{{ __('Linked source control') }}</option>
                        </select>
                    </div>

                    @if ($repo_source === 'provider')
                        <div>
                            <x-input-label for="source_control_account_id" :value="__('Source-control account')" />
                            <select id="source_control_account_id" wire:model.live="source_control_account_id" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                                @foreach ($linkedSourceControlAccounts as $account)
                                    <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>

                @if ($repo_source === 'manual')
                    <div class="mt-5">
                        <x-input-label for="repository_url" :value="__('Repository URL')" />
                        <x-text-input id="repository_url" wire:model="repository_url" type="text" class="mt-2 block w-full font-mono text-sm" placeholder="https://github.com/org/repo.git" />
                        <x-input-error :messages="$errors->get('repository_url')" class="mt-2" />
                    </div>
                @else
                    <div class="mt-5">
                        <x-input-label for="repository_selection" :value="__('Repository')" />
                        <select id="repository_selection" wire:model="repository_selection" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                            @foreach ($availableRepositories as $repository)
                                <option value="{{ $repository['url'] }}">{{ $repository['label'] }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('repository_selection')" class="mt-2" />
                    </div>
                @endif

                <div class="mt-5 grid gap-6 md:grid-cols-2">
                    <div>
                        <x-input-label for="repository_branch" :value="__('Branch')" />
                        <x-text-input id="repository_branch" wire:model="repository_branch" type="text" class="mt-2 block w-full font-mono text-sm" />
                        <x-input-error :messages="$errors->get('repository_branch')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="repository_subdirectory" :value="__('Subdirectory (optional)')" />
                        <x-text-input id="repository_subdirectory" wire:model="repository_subdirectory" type="text" class="mt-2 block w-full font-mono text-sm" placeholder="apps/web" />
                        <x-input-error :messages="$errors->get('repository_subdirectory')" class="mt-2" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button type="button" wire:click="inspectRepository" class="inline-flex items-center rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-sky-700">
                        {{ __('Inspect repository') }}
                    </button>
                </div>
            </section>

            @if ($has_inspection)
                <section class="rounded-2xl border border-sky-200 bg-sky-50/70 p-6 shadow-sm">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">{{ __('Inspection review') }}</p>
                    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-2xl border border-sky-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Framework') }}</p>
                            <p class="mt-2 text-sm font-medium text-slate-900">{{ data_get($inspection, 'detection.framework', 'unknown') }}</p>
                        </div>
                        <div class="rounded-2xl border border-sky-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Runtime') }}</p>
                            <p class="mt-2 text-sm font-medium text-slate-900">{{ data_get($inspection, 'detection.target_kind', 'docker') }}</p>
                        </div>
                        <div class="rounded-2xl border border-sky-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Confidence') }}</p>
                            <p class="mt-2 text-sm font-medium text-slate-900">{{ data_get($inspection, 'detection.confidence', 'unknown') }}</p>
                        </div>
                        <div class="rounded-2xl border border-sky-200 bg-white p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('App port') }}</p>
                            <p class="mt-2 text-sm font-medium text-slate-900">{{ data_get($inspection, 'detection.app_port') ?: 'default' }}</p>
                        </div>
                    </div>
                    <div class="mt-4 rounded-2xl border border-sky-200 bg-white p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Detection notes') }}</p>
                        <ul class="mt-3 space-y-2 text-sm text-slate-700">
                            @foreach ((array) data_get($inspection, 'detection.reasons', []) as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                            @foreach ((array) data_get($inspection, 'detection.warnings', []) as $warning)
                                <li class="text-amber-700">{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                </section>

                @php
                    $isCloudTarget = str_starts_with($target_family, 'digitalocean_') || str_starts_with($target_family, 'aws_');
                    $isLocalTarget = str_starts_with($target_family, 'local_');
                    $cloudLabel = str_starts_with($target_family, 'aws_') ? __('AWS') : __('DigitalOcean');
                    $accountReady = $isLocalTarget || ($isCloudTarget && $providerCredentials !== []);
                @endphp

                {{-- Card A: Container target tiles --}}
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">{{ __('Choose the container target') }}</h2>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Pick where the inspected repo should run — Docker host or Kubernetes cluster, on DigitalOcean or AWS.') }}</p>
                        </div>
                        <a href="{{ $connectCredentialUrl }}" wire:navigate class="text-sm font-medium text-sky-700 transition-colors hover:text-sky-900">{{ __('Manage credentials') }} →</a>
                    </div>

                    @if (! $hasAnyCloudCredentials)
                        <div data-testid="empty-credential-notice" class="rounded-2xl border border-amber-200 bg-amber-50/80 p-5">
                            <p class="text-sm font-semibold text-amber-900">{{ __('No connected providers yet.') }}</p>
                            <p class="mt-1 text-sm text-amber-800">{{ __('Connect a DigitalOcean or AWS API token to launch a container target.') }}</p>
                            <a href="{{ $connectCredentialUrl }}" wire:navigate class="mt-3 inline-flex items-center rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                                {{ __('Connect a provider') }} →
                            </a>
                        </div>
                    @endif

                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($targetOptions as $option)
                            @php
                                $linked = $targetBadges[$option['id']]['linked'] ?? false;
                                $description = $targetDescriptions[$option['id']] ?? '';
                            @endphp
                            <label class="rounded-2xl border {{ $target_family === $option['id'] ? 'border-sky-400 bg-sky-50' : 'border-slate-200 bg-slate-50/70' }} p-4 cursor-pointer">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-start gap-3">
                                        <input type="radio" wire:model.live="target_family" value="{{ $option['id'] }}" class="mt-1">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ $option['label'] }}</p>
                                            <p class="mt-1 text-xs text-slate-600">{{ $description }}</p>
                                        </div>
                                    </div>
                                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $linked ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-amber-50 text-amber-800 ring-1 ring-amber-200' }}">
                                        @if ($linked)
                                            <x-heroicon-m-check-circle class="h-3 w-3" />
                                            {{ __('Connected') }}
                                        @else
                                            <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                                            {{ __('Needs account') }}
                                        @endif
                                    </span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </section>

                {{-- Card B: Account / credential --}}
                @if ($isCloudTarget)
                    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                        <h2 class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Account') }}</h2>

                        @if ($providerCredentials === [])
                            <div data-testid="empty-credential-notice" class="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-900">
                                <p class="font-semibold">{{ __('No :provider credentials connected.', ['provider' => $cloudLabel]) }}</p>
                                <p class="mt-1 text-amber-800">{{ __('Connect a :provider API token before launching this target.', ['provider' => $cloudLabel]) }}</p>
                                <a href="{{ $connectCredentialUrl }}" wire:navigate class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-amber-900 underline hover:text-amber-700">
                                    {{ __('Connect :provider', ['provider' => $cloudLabel]) }} →
                                </a>
                            </div>
                        @else
                            <div>
                                <x-input-label for="provider_credential_id" :value="__('Provider credential')" />
                                <select id="provider_credential_id" wire:model.live="provider_credential_id" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                                    @foreach ($providerCredentials as $credential)
                                        <option value="{{ $credential['id'] }}">{{ $credential['name'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-2" />
                            </div>
                        @endif
                    </section>
                @endif

                {{-- Card C: Server name + region/size + k8s + launch --}}
                @if ($accountReady)
                    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-6">
                        <div>
                            <x-input-label for="server_name" :value="__('Server name')" />
                            <x-text-input id="server_name" wire:model.live.debounce.500ms="server_name" type="text" class="mt-2 block w-full font-mono text-sm" />
                            <p class="mt-1 text-xs text-slate-500">{{ __('Used as the server label in dply. You can rename it after creation.') }}</p>
                            <x-input-error :messages="$errors->get('server_name')" class="mt-2" />
                        </div>

                        @if ($isCloudTarget)
                            <div class="grid gap-6 md:grid-cols-2">
                                @if (($cloudCatalog['regions'] ?? []) !== [])
                                    <div>
                                        <x-input-label for="cloud_region" :value="__('Region')" />
                                        <select id="cloud_region" wire:model.live="cloud_region" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                                            @foreach ($cloudCatalog['regions'] as $region)
                                                <option value="{{ $region['value'] }}">{{ $region['label'] }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('cloud_region')" class="mt-2" />
                                    </div>
                                @endif
                                @if (($cloudCatalog['sizes'] ?? []) !== [] && str_ends_with($target_family, '_docker'))
                                    <div>
                                        <x-input-label for="cloud_size" :value="__('Size')" />
                                        <select id="cloud_size" wire:model.live="cloud_size" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                                            @foreach ($cloudCatalog['sizes'] as $size)
                                                <option value="{{ $size['value'] }}">{{ $size['label'] }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('cloud_size')" class="mt-2" />
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if (str_contains($target_family, 'kubernetes'))
                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <x-input-label for="cluster_name" :value="__('Cluster name')" />
                                    <x-text-input id="cluster_name" wire:model="cluster_name" type="text" class="mt-2 block w-full" />
                                </div>
                                <div>
                                    <x-input-label for="kubernetes_namespace" :value="__('Namespace')" />
                                    <x-text-input id="kubernetes_namespace" wire:model="kubernetes_namespace" type="text" class="mt-2 block w-full" />
                                </div>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 pt-4">
                            <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Cancel') }}</a>
                            <button type="button" wire:click="launch" class="inline-flex items-center rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-sky-700">
                                {{ __('Launch target') }}
                            </button>
                        </div>
                    </section>
                @endif
            @endif
        </div>
    </div>
</div>
