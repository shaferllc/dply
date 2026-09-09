@php
    $labelCls = 'block text-xs font-semibold uppercase tracking-wide text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';

    // The shared settings sidebar partial reads these from its host view.
    $runtimeMode = $site->runtimeTargetMode();
    $runtimeTarget = $site->runtimeTarget();
    $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
    $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
    $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
    $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
    $section = 'git-sources';
    $routingTab = 'domains';
    $laravel_tab = 'commands';

    $statusTone = [
        'synced' => 'bg-brand-sage/25 text-brand-forest',
        'syncing' => 'bg-brand-gold/20 text-brand-rust',
        'pending' => 'bg-brand-ink/[0.06] text-brand-mist',
        'error' => 'bg-red-100 text-red-700',
    ];
@endphp

{{-- Themes & plugins from their own Git repos. Single root element: Livewire
     drops the snapshot when a component has more than one. --}}
<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Themes & plugins'),
        'currentIcon' => 'code-bracket',
    ])

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9 space-y-8">
            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    icon="heroicon-o-code-bracket"
                    :title="__('Themes & plugins from Git')"
                    :description="$bedrock
                        ? __('This is a Bedrock site, so each one is added as a Composer dependency from its repository.')
                        : __('Each one is cloned into wp-content from its own repository, with its own deploy key.')"
                />

                <div class="space-y-6 p-6 sm:p-7">
                    <form wire:submit="add" class="space-y-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="gs-kind" class="{{ $labelCls }}">{{ __('Type') }}</label>
                                <select id="gs-kind" wire:model="kind" class="{{ $inputCls }}">
                                    <option value="theme">{{ __('Theme') }}</option>
                                    <option value="plugin">{{ __('Plugin') }}</option>
                                </select>
                                <x-input-error :messages="$errors->get('kind')" class="mt-2" />
                            </div>

                            <div>
                                <label for="gs-branch" class="{{ $labelCls }}">{{ __('Branch') }}</label>
                                <input id="gs-branch" type="text" wire:model="git_branch" class="{{ $inputCls }}" />
                                <x-input-error :messages="$errors->get('git_branch')" class="mt-2" />
                            </div>
                        </div>

                        <div>
                            <label for="gs-url" class="{{ $labelCls }}">{{ __('Repository URL') }}</label>
                            <input id="gs-url" type="text" wire:model.blur="repository_url"
                                placeholder="git@github.com:acme/my-theme.git" class="{{ $inputCls }}" />
                            <p class="mt-1.5 text-xs text-brand-moss">
                                {{ __('An SSH URL lets dply use a per-source deploy key. Public HTTPS URLs work too.') }}
                            </p>
                            <x-input-error :messages="$errors->get('repository_url')" class="mt-2" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="gs-slug" class="{{ $labelCls }}">{{ __('Directory slug') }}</label>
                                <input id="gs-slug" type="text" wire:model="slug" class="{{ $inputCls }}" />
                                <p class="mt-1.5 text-xs text-brand-moss">
                                    {{ $bedrock ? __('web/app/themes/…') : __('wp-content/themes/…') }}
                                </p>
                                <x-input-error :messages="$errors->get('slug')" class="mt-2" />
                            </div>

                            @if ($bedrock)
                                <div>
                                    <label for="gs-package" class="{{ $labelCls }}">{{ __('Composer package') }}</label>
                                    <input id="gs-package" type="text" wire:model="composer_package"
                                        placeholder="acme/my-theme" class="{{ $inputCls }}" />
                                    <p class="mt-1.5 text-xs text-brand-moss">
                                        {{ __('Optional — defaults to the vendor/name in the repository URL.') }}
                                    </p>
                                    <x-input-error :messages="$errors->get('composer_package')" class="mt-2" />
                                </div>
                            @endif
                        </div>

                        <div class="flex justify-end">
                            <x-spinner-button type="submit" wire:target="add">
                                {{ __('Add and sync') }}
                            </x-spinner-button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    icon="heroicon-o-rectangle-stack"
                    :title="__('Connected repositories')"
                    :description="__('Each sync fetches the branch tip and replaces the working copy on the server.')"
                />

                <div class="p-6 sm:p-7">
                    @forelse ($sources as $source)
                        <div class="mb-4 rounded-xl border border-brand-ink/10 bg-white/80 p-4 last:mb-0">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold text-brand-ink">{{ $source->slug }}</span>
                                        <span class="rounded-full bg-brand-ink/[0.06] px-2 py-0.5 text-2xs font-bold uppercase tracking-wide text-brand-mist">
                                            {{ $source->kind }}
                                        </span>
                                        <span class="rounded-full px-2 py-0.5 text-2xs font-bold uppercase tracking-wide {{ $statusTone[$source->status] ?? $statusTone['pending'] }}">
                                            {{ $source->status }}
                                        </span>
                                    </div>
                                    <p class="mt-1 truncate text-xs text-brand-moss">
                                        {{ $source->repository_url }} · {{ $source->git_branch }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-brand-mist">
                                        {{ $source->relativePath($bedrock) }}
                                        @if ($source->last_synced_at)
                                            · {{ __('synced :when', ['when' => $source->last_synced_at->diffForHumans()]) }}
                                        @endif
                                        @if ($source->last_synced_commit)
                                            · {{ Str::limit($source->last_synced_commit, 12, '') }}
                                        @endif
                                    </p>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    <x-spinner-button wire:click="resync('{{ $source->id }}')" wire:target="resync('{{ $source->id }}')" variant="secondary">
                                        {{ __('Re-sync') }}
                                    </x-spinner-button>
                                    <x-spinner-button wire:click="remove('{{ $source->id }}')" wire:target="remove('{{ $source->id }}')" variant="danger">
                                        {{ __('Remove') }}
                                    </x-spinner-button>
                                </div>
                            </div>

                            @if ($source->status === 'error' && $source->last_error)
                                <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs leading-relaxed text-red-700">
                                    {{ $source->last_error }}
                                </p>
                            @endif

                            @if ($source->deploy_key_public)
                                <details class="mt-3">
                                    <summary class="cursor-pointer text-xs font-semibold text-brand-forest">
                                        {{ __('Deploy key (add to the repository for private access)') }}
                                    </summary>
                                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-cream/70 px-3 py-2 text-2xs leading-relaxed text-brand-ink">{{ $source->deploy_key_public }}</pre>
                                </details>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-brand-moss">
                            {{ __('No theme or plugin repositories yet. Add one above.') }}
                        </p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</div>
