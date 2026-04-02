<div>
    <div class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <a href="{{ route('launches.containers') }}" wire:navigate class="text-sm font-medium text-sky-700 hover:text-sky-900">{{ __('← Back to Containers') }}</a>
            <p class="mt-4 text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">{{ __('Repo-first containers') }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">{{ __('Launch a container target from a repo') }}</h1>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                {{ __('Inspect the repo first, review the inferred runtime, then choose whether to launch on Local Docker or continue into a remote Docker or Kubernetes target.') }}
            </p>
        </div>
    </div>

    <div class="py-10">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 space-y-6">
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

                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">{{ __('Choose the container target') }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Keep the same inspected repo and continue into a local or remote container target.') }}</p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($targetOptions as $option)
                            <label class="rounded-2xl border {{ $target_family === $option['id'] ? 'border-sky-400 bg-sky-50' : 'border-slate-200 bg-slate-50/70' }} p-4 cursor-pointer">
                                <div class="flex items-start gap-3">
                                    <input type="radio" wire:model.live="target_family" value="{{ $option['id'] }}" class="mt-1">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">{{ $option['label'] }}</p>
                                        <p class="mt-1 text-xs text-slate-600">{{ str_replace('_', ' ', $option['id']) }}</p>
                                    </div>
                                </div>
                            </label>
                        @endforeach
                    </div>

                    @if ($providerCredentials !== [])
                        <div class="grid gap-6 md:grid-cols-3">
                            <div>
                                <x-input-label for="provider_credential_id" :value="__('Provider credential')" />
                                <select id="provider_credential_id" wire:model.live="provider_credential_id" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                                    @foreach ($providerCredentials as $credential)
                                        <option value="{{ $credential['id'] }}">{{ $credential['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if (($cloudCatalog['regions'] ?? []) !== [])
                                <div>
                                    <x-input-label for="cloud_region" :value="__('Region')" />
                                    <select id="cloud_region" wire:model="cloud_region" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                                        @foreach ($cloudCatalog['regions'] as $region)
                                            <option value="{{ $region['value'] }}">{{ $region['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            @if (($cloudCatalog['sizes'] ?? []) !== [] && str_ends_with($target_family, '_docker'))
                                <div>
                                    <x-input-label for="cloud_size" :value="__('Size')" />
                                    <select id="cloud_size" wire:model="cloud_size" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                                        @foreach ($cloudCatalog['sizes'] as $size)
                                            <option value="{{ $size['value'] }}">{{ $size['label'] }}</option>
                                        @endforeach
                                    </select>
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

                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <a href="{{ route('launches.containers') }}" wire:navigate class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Cancel') }}</a>
                        <button type="button" wire:click="launch" class="inline-flex items-center rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-sky-700">
                            {{ __('Launch target') }}
                        </button>
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
