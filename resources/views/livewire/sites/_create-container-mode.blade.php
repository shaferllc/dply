<div class="space-y-6" data-testid="sites-create-container-mode">
    <section data-testid="container-oss-presets" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex items-baseline justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">{{ __('Try an open-source preset') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ __('One-click fill the repo URL with a real OSS project. You can edit the fields before submitting.') }}</p>
            </div>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($containerOssPresets as $preset)
                <button
                    type="button"
                    wire:click="applyContainerOssPreset('{{ $preset['id'] }}')"
                    class="rounded-2xl border border-slate-200 bg-slate-50/70 p-3 text-left transition-colors hover:border-sky-300 hover:bg-sky-50"
                >
                    <p class="text-sm font-semibold text-slate-900">{{ $preset['label'] }}</p>
                    <p class="mt-1 text-xs text-slate-600">{{ $preset['description'] }}</p>
                    <p class="mt-2 truncate font-mono text-[11px] text-slate-500">{{ \Illuminate\Support\Str::after($preset['url'], 'https://') }}</p>
                </button>
            @endforeach
        </div>
    </section>

    <form wire:submit.prevent="storeContainer" class="space-y-6">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
            <div>
                <h2 class="text-base font-semibold text-slate-900">{{ __('Repository') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('Where dply finds the Dockerfile or Kubernetes manifest. Inspection runs automatically when you tab away from the URL.') }}</p>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <x-input-label for="container_repo_source" :value="__('Repo source')" />
                        <x-connect-provider-link>{{ __('Connect a provider') }} &rarr;</x-connect-provider-link>
                    </div>
                    <select id="container_repo_source" wire:model.live="container_repo_source" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                        <option value="manual">{{ __('Manual Git URL') }}</option>
                        <option value="provider" @disabled($containerLinkedSourceControlAccounts === [])>{{ __('Linked source control') }}</option>
                    </select>
                </div>

                @if ($container_repo_source === 'provider')
                    <div>
                        <x-input-label for="container_source_control_account_id" :value="__('Source-control account')" />
                        <select id="container_source_control_account_id" wire:model.live="container_source_control_account_id" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                            @foreach ($containerLinkedSourceControlAccounts as $account)
                                <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            @if ($container_repo_source === 'manual')
                <div>
                    <x-input-label for="container_repository_url" :value="__('Repository URL')" />
                    <x-text-input
                        id="container_repository_url"
                        wire:model.blur="container_repository_url"
                        wire:change="inspectContainerRepository"
                        type="text"
                        class="mt-2 block w-full font-mono text-sm"
                        placeholder="https://github.com/org/repo.git"
                    />
                    <x-input-error :messages="$errors->get('container_repository_url')" class="mt-2" />
                </div>
            @else
                <div>
                    <x-input-label for="container_repository_selection" :value="__('Repository')" />
                    <select
                        id="container_repository_selection"
                        wire:model.live="container_repository_selection"
                        wire:change="inspectContainerRepository"
                        class="mt-2 block w-full rounded-xl border-slate-300 text-sm"
                    >
                        @foreach ($containerAvailableRepositories as $repository)
                            <option value="{{ $repository['url'] }}">{{ $repository['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('container_repository_selection')" class="mt-2" />
                </div>
            @endif

            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    <x-input-label for="container_repository_branch" :value="__('Branch')" />
                    <x-text-input
                        id="container_repository_branch"
                        wire:model.blur="container_repository_branch"
                        wire:change="inspectContainerRepository"
                        type="text"
                        class="mt-2 block w-full font-mono text-sm"
                    />
                    <x-input-error :messages="$errors->get('container_repository_branch')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="container_repository_subdirectory" :value="__('Subdirectory (optional)')" />
                    <x-text-input
                        id="container_repository_subdirectory"
                        wire:model.blur="container_repository_subdirectory"
                        wire:change="inspectContainerRepository"
                        type="text"
                        class="mt-2 block w-full font-mono text-sm"
                        placeholder="apps/web"
                    />
                    <x-input-error :messages="$errors->get('container_repository_subdirectory')" class="mt-2" />
                </div>
            </div>
        </section>

        @if ($container_has_inspection)
            <section data-testid="container-inspection-preview" class="rounded-2xl border border-sky-200 bg-sky-50/70 p-6 shadow-sm">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">{{ __('Detection') }}</p>
                <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-sky-200 bg-white p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Framework') }}</p>
                        <p class="mt-2 text-sm font-medium text-slate-900">{{ data_get($container_inspection, 'detection.framework', 'unknown') }}</p>
                    </div>
                    <div class="rounded-2xl border border-sky-200 bg-white p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Runtime') }}</p>
                        <p class="mt-2 text-sm font-medium text-slate-900">{{ data_get($container_inspection, 'detection.target_kind', 'docker') }}</p>
                    </div>
                    <div class="rounded-2xl border border-sky-200 bg-white p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Confidence') }}</p>
                        <p class="mt-2 text-sm font-medium text-slate-900">{{ data_get($container_inspection, 'detection.confidence', 'unknown') }}</p>
                    </div>
                    <div class="rounded-2xl border border-sky-200 bg-white p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('App port') }}</p>
                        <p class="mt-2 text-sm font-medium text-slate-900">{{ data_get($container_inspection, 'detection.app_port') ?: 'default' }}</p>
                    </div>
                </div>
                @php
                    $reasons = (array) data_get($container_inspection, 'detection.reasons', []);
                    $warnings = (array) data_get($container_inspection, 'detection.warnings', []);
                @endphp
                @if ($reasons !== [] || $warnings !== [])
                    <div class="mt-4 rounded-2xl border border-sky-200 bg-white p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Detection notes') }}</p>
                        <ul class="mt-3 space-y-2 text-sm text-slate-700">
                            @foreach ($reasons as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                            @foreach ($warnings as $warning)
                                <li class="text-amber-700">{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endif

        @if ($server->hostKind() === \App\Models\Server::HOST_KIND_KUBERNETES)
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('Kubernetes namespace') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('Containers default to the server\'s namespace; override per app if you need to isolate it.') }}</p>
                <div class="mt-4">
                    <x-input-label for="container_kubernetes_namespace" :value="__('Namespace')" />
                    <x-text-input
                        id="container_kubernetes_namespace"
                        wire:model.live.debounce.500ms="container_kubernetes_namespace"
                        type="text"
                        class="mt-2 block w-full font-mono text-sm"
                        placeholder="default"
                    />
                    <x-input-error :messages="$errors->get('container_kubernetes_namespace')" class="mt-2" />
                </div>
            </section>
        @endif

        <footer class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 pt-4">
            <a href="{{ route('servers.overview', $server) }}" wire:navigate class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Cancel') }}</a>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="storeContainer"
                class="inline-flex items-center gap-2 rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-sky-700 disabled:cursor-wait disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="storeContainer">{{ __('Add container') }}</span>
                <span wire:loading wire:target="storeContainer">{{ __('Queuing…') }}</span>
            </button>
        </footer>
    </form>
</div>
