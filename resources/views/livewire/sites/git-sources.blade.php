@php
    $labelCls = 'block text-xs font-medium text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';

    $statusTone = [
        'synced' => 'bg-brand-sage/25 text-brand-forest',
        'syncing' => 'bg-brand-gold/20 text-brand-rust',
        'pending' => 'bg-brand-ink/[0.06] text-brand-mist',
        'error' => 'bg-red-100 text-red-700',
    ];
@endphp

{{-- Themes & plugins from their own Git repos. Rendered as the Git tab of the
     WordPress section, so it carries the same compact tab chrome and no page
     chrome of its own. Single root element: Livewire drops the snapshot when a
     component has more than one. --}}
<div>
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Themes & plugins from Git') }}</h3>
            <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">
                {{ $bedrock
                    ? __('This is a Bedrock site, so each one is added as a Composer dependency from its repository.')
                    : __('Each one is cloned into wp-content from its own repository — with your connected account, or a per-source deploy key for a pasted URL.') }}
            </p>
        </div>
    </div>

    <form wire:submit="add" class="space-y-4 border-b border-brand-ink/10 px-3 py-3 sm:px-4">
        <div class="max-w-xs">
            <label for="gs-kind" class="{{ $labelCls }}">{{ __('Type') }}</label>
            <select id="gs-kind" wire:model="kind" class="{{ $inputCls }}">
                <option value="theme">{{ __('Theme') }}</option>
                <option value="plugin">{{ __('Plugin') }}</option>
            </select>
            <x-input-error :messages="$errors->get('kind')" class="mt-1" />
        </div>

        {{-- The same connected-account repo picker as every other repo field in
             dply. A repo picked here clones with that account's access, so there
             is no deploy key to install; a pasted URL still gets one. The repo
             list is a provider API call, so it loads on wire:init, not mount(). --}}
        <div wire:init="primeGitSourceRepositories" class="space-y-3">
            @include('livewire.sites.partials._git-repository-configurator', [
                'idPrefix' => 'gs',
                'reposLoading' => ! $gitSourceReposPrimed,
            ])
            <x-input-error :messages="$errors->get('git_repository_url')" class="mt-1" />
        </div>

        <div @class(['grid gap-3', 'sm:grid-cols-3' => $bedrock, 'sm:grid-cols-2' => ! $bedrock])>
            <div>
                <label for="gs-branch" class="{{ $labelCls }}">{{ __('Branch') }}</label>
                <input id="gs-branch" type="text" wire:model="git_branch" class="{{ $inputCls }} font-mono" />
                <p class="mt-1 text-2xs text-brand-mist">{{ __('Picking a repository fills in its default branch.') }}</p>
                <x-input-error :messages="$errors->get('git_branch')" class="mt-1" />
            </div>

            <div>
                <label for="gs-slug" class="{{ $labelCls }}">{{ __('Directory slug') }}</label>
                <input id="gs-slug" type="text" wire:model="slug" class="{{ $inputCls }} font-mono" />
                <p class="mt-1 text-2xs text-brand-mist">
                    {{ $bedrock ? __('web/app/themes/…') : __('wp-content/themes/…') }}
                </p>
                <x-input-error :messages="$errors->get('slug')" class="mt-1" />
            </div>

            @if ($bedrock)
                <div>
                    <label for="gs-package" class="{{ $labelCls }}">{{ __('Composer package') }}</label>
                    <input id="gs-package" type="text" wire:model="composer_package"
                        placeholder="acme/my-theme" class="{{ $inputCls }} font-mono" />
                    <p class="mt-1 text-2xs text-brand-mist">
                        {{ __('Optional — defaults to the vendor/name in the repository URL.') }}
                    </p>
                    <x-input-error :messages="$errors->get('composer_package')" class="mt-1" />
                </div>
            @endif
        </div>

        <div class="flex justify-end">
            <x-spinner-button size="xs" variant="primary" type="submit" icon="heroicon-o-plus" target="add">
                {{ __('Add and sync') }}
            </x-spinner-button>
        </div>
    </form>

    <div class="border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2 sm:px-4">
        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Connected repositories') }}</h3>
        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Each sync fetches the branch tip and replaces the working copy on the server.') }}</p>
    </div>

    @forelse ($sources as $source)
        <div class="border-b border-brand-ink/10 px-3 py-2.5 last:border-b-0 sm:px-4">
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
                    <p class="mt-0.5 truncate font-mono text-xs text-brand-moss">
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
                    <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-arrow-path" wire:click="resync('{{ $source->id }}')" target="resync('{{ $source->id }}')">
                        {{ __('Re-sync') }}
                    </x-spinner-button>
                    <x-spinner-button size="xs" variant="danger" type="button" wire:click="remove('{{ $source->id }}')" target="remove('{{ $source->id }}')">
                        {{ __('Remove') }}
                    </x-spinner-button>
                </div>
            </div>

            @if ($source->status === 'error' && $source->last_error)
                <p class="mt-2 rounded-md bg-red-50 px-2.5 py-1.5 text-xs leading-relaxed text-red-700">
                    {{ $source->last_error }}
                </p>
            @endif

            @if ($source->deploy_key_public)
                <details class="mt-2">
                    <summary class="cursor-pointer text-xs font-semibold text-brand-forest">
                        {{ __('Deploy key (add to the repository for private access)') }}
                    </summary>
                    <pre class="mt-1.5 overflow-x-auto rounded-md bg-brand-sand/50 px-2.5 py-2 font-mono text-2xs leading-relaxed text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $source->deploy_key_public }}</pre>
                </details>
            @endif
        </div>
    @empty
        <p class="px-3 py-3 text-sm text-brand-moss sm:px-4">
            {{ __('No theme or plugin repositories yet. Add one above.') }}
        </p>
    @endforelse
</div>
