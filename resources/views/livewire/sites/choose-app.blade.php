<div>
    @php
        $selectedTile = collect($tiles)->firstWhere('key', $selected);
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @include('livewire.sites.partials.workspace-breadcrumb-bar', [
            'server' => $server,
            'site' => $site,
            'currentLabel' => __('Connect application'),
            'currentIcon' => 'code-bracket-square',
        ])

        <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
            @include('livewire.sites.settings.partials.sidebar')

            <main class="min-w-0 space-y-6 lg:col-span-9">
                <x-page-header
                    :title="__('Connect an application')"
                    :description="__('Install a fresh app, connect an existing repository, or leave it blank. WordPress and Laravel set themselves up — database included.')"
                    :show-documentation="false"
                    flush
                    compact
                />

                {{-- Tile grid --}}
                <div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($tiles as $tile)
                            @php
                                $isSelected = $selected === $tile['key'];
                                $isInstaller = ($tile['kind'] ?? '') === 'scaffold';
                            @endphp
                            <button
                                type="button"
                                wire:click="selectTile('{{ $tile['key'] }}')"
                                @class([
                                    'group relative flex items-start gap-3 rounded-2xl border p-4 text-left transition-all',
                                    'border-brand-forest bg-white shadow-md shadow-brand-ink/5 ring-2 ring-brand-sage/30' => $isSelected,
                                    'border-brand-ink/10 bg-white/80 shadow-sm hover:-translate-y-0.5 hover:border-brand-ink/20 hover:shadow-md' => ! $isSelected,
                                ])
                            >
                                <span @class([
                                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 transition-colors',
                                    'bg-brand-forest text-brand-cream ring-brand-forest/20' => $isSelected,
                                    'bg-brand-sage/12 text-brand-forest ring-brand-sage/15 group-hover:bg-brand-sage/20' => ! $isSelected,
                                ])>
                                    <x-dynamic-component :component="$tile['icon']" class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2">
                                        <span class="block text-sm font-semibold text-brand-ink">{{ $tile['label'] }}</span>
                                        @if ($isInstaller)
                                            <span class="inline-flex items-center rounded-full bg-brand-gold/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-rust">{{ __('Auto-install') }}</span>
                                        @endif
                                    </span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ $tile['description'] }}</span>
                                </span>
                                @if ($isSelected)
                                    <span class="absolute right-3 top-3 text-brand-forest">
                                        <x-heroicon-s-check-circle class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('selected')" class="mt-3" />
                </div>

                {{-- Config + submit --}}
                @if ($selectedTile)
                    <form wire:submit="run">
                        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-md shadow-brand-ink/5">
                            <div class="flex items-center gap-3 rounded-t-2xl border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-4">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                    <x-dynamic-component :component="$selectedTile['icon']" class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Configure :app', ['app' => $selectedTile['label']]) }}</h2>
                                    <p class="text-xs text-brand-moss">{{ $selectedTile['description'] }}</p>
                                </div>
                            </div>

                            <div class="space-y-6 p-6 sm:p-7">
                                @if ($selectedTile['kind'] === 'scaffold' && ($selectedTile['needs_admin_email'] ?? false))
                                    <div>
                                        <x-input-label for="scaffold-admin-email" :value="__('Admin email')" required />
                                        <x-text-input
                                            id="scaffold-admin-email"
                                            type="email"
                                            wire:model="scaffold_admin_email"
                                            autocomplete="off"
                                            class="mt-1.5"
                                        />
                                        <p class="mt-1.5 flex items-start gap-1.5 text-xs text-brand-moss">
                                            <x-heroicon-o-sparkles class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-gold" aria-hidden="true" />
                                            <span>{{ __('Used to create the first admin user. A database is provisioned automatically.') }}</span>
                                        </p>
                                        <x-input-error :messages="$errors->get('scaffold_admin_email')" class="mt-2" />
                                    </div>
                                @elseif ($selectedTile['kind'] === 'scaffold')
                                    <div class="space-y-2 text-sm leading-relaxed text-brand-moss">
                                        <p class="flex items-start gap-2">
                                            <x-heroicon-o-sparkles class="mt-0.5 h-4 w-4 shrink-0 text-brand-gold" aria-hidden="true" />
                                            <span>{{ __(':app installs automatically — Dply runs the installer on your server.', ['app' => $selectedTile['label']]) }}</span>
                                        </p>
                                        @if ($selectedTile['needs_db'] ?? false)
                                            <p class="flex items-start gap-2">
                                                <x-heroicon-o-circle-stack class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                                <span>{{ __('A database and user are provisioned for you.') }}</span>
                                            </p>
                                        @endif
                                        @if ($selectedTile['recipe']['finish_in_browser'] ?? false)
                                            <p class="flex items-start gap-2">
                                                <x-heroicon-o-arrow-top-right-on-square class="mt-0.5 h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                                                <span>{{ __('Finish the quick setup wizard in your browser once it’s live.') }}</span>
                                            </p>
                                        @endif
                                    </div>
                                @elseif ($selectedTile['kind'] === 'blank')
                                    <p class="flex items-start gap-2 rounded-xl border border-brand-ink/10 bg-brand-cream/70 px-4 py-3 text-sm leading-relaxed text-brand-moss">
                                        <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                                        <span>{{ __('Your site stays empty and keeps serving its splash page. Come back to this picker any time to install a real application.') }}</span>
                                    </p>
                                @else
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        @if (count($linkedSourceControlAccounts) > 0)
                                            {{-- Source toggle: connected provider vs pasted URL --}}
                                            <div class="inline-flex rounded-xl border border-brand-ink/10 bg-brand-cream/60 p-1">
                                                <button type="button" wire:click="$set('repo_source', 'provider')"
                                                    @class([
                                                        'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                                        'bg-white text-brand-ink shadow-sm' => $repo_source === 'provider',
                                                        'text-brand-moss hover:text-brand-ink' => $repo_source !== 'provider',
                                                    ])>
                                                    <x-heroicon-o-link class="h-3.5 w-3.5" aria-hidden="true" />
                                                    {{ __('Connected provider') }}
                                                </button>
                                                <button type="button" wire:click="$set('repo_source', 'manual')"
                                                    @class([
                                                        'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                                        'bg-white text-brand-ink shadow-sm' => $repo_source === 'manual',
                                                        'text-brand-moss hover:text-brand-ink' => $repo_source !== 'manual',
                                                    ])>
                                                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5" aria-hidden="true" />
                                                    {{ __('Paste a URL') }}
                                                </button>
                                            </div>
                                        @else
                                            <span></span>
                                        @endif
                                        <x-connect-provider-link>{{ count($linkedSourceControlAccounts) > 0
                                            ? __('Connect another account')
                                            : __('Connect a provider') }} &rarr;</x-connect-provider-link>
                                    </div>

                                    @if ($repo_source === 'provider' && count($linkedSourceControlAccounts) > 0)
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <x-input-label for="sc-account" :value="__('Account')" required />
                                                <span wire:loading wire:target="source_control_account_id" class="inline-flex items-center gap-1 text-[11px] font-medium text-brand-moss">
                                                    <x-spinner size="sm" />
                                                    {{ __('Loading repositories…') }}
                                                </span>
                                            </div>
                                            <select id="sc-account" wire:model.live="source_control_account_id"
                                                wire:loading.attr="disabled" wire:target="source_control_account_id"
                                                class="dply-input mt-1.5 disabled:cursor-progress disabled:opacity-60">
                                                @foreach ($linkedSourceControlAccounts as $account)
                                                    <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$errors->get('source_control_account_id')" class="mt-2" />
                                        </div>
                                        <div>
                                            <x-input-label for="sc-repo" :value="__('Repository')" required />
                                            @if (count($availableRepositories) > 0)
                                                @php
                                                    $selectedRepository = collect($availableRepositories)->firstWhere('url', $repository_selection);
                                                @endphp
                                                <div x-data="{ open: false, search: '' }" class="relative mt-1.5"
                                                    wire:loading.class="opacity-60 pointer-events-none" wire:target="source_control_account_id">
                                                    <button
                                                        id="sc-repo"
                                                        type="button"
                                                        x-on:click="open = !open; $nextTick(() => open && $refs.repoSearch && $refs.repoSearch.focus())"
                                                        x-on:keydown.escape.window="open = false"
                                                        x-bind:aria-expanded="open.toString()"
                                                        aria-haspopup="listbox"
                                                        wire:loading.attr="disabled" wire:target="source_control_account_id"
                                                        class="flex w-full items-center justify-between gap-3 rounded-xl border border-brand-ink/15 bg-white px-3.5 py-2.5 text-left text-sm shadow-sm transition focus:border-brand-ink focus:outline-none focus:ring-1 focus:ring-brand-ink"
                                                    >
                                                        <span class="min-w-0 flex-1 truncate font-mono text-sm text-brand-ink">
                                                            <span wire:loading.remove wire:target="source_control_account_id">{{ $selectedRepository['label'] ?? __('Select repository') }}</span>
                                                            <span wire:loading wire:target="source_control_account_id" class="inline-flex items-center gap-1.5 text-brand-moss">
                                                                <x-spinner size="sm" />
                                                                {{ __('Loading repositories…') }}
                                                            </span>
                                                        </span>
                                                        <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-brand-moss transition-transform" x-bind:class="{ 'rotate-180': open }" aria-hidden="true" />
                                                    </button>

                                                    <div
                                                        x-cloak
                                                        x-show="open"
                                                        x-transition.origin.top
                                                        x-on:click.outside="open = false"
                                                        role="listbox"
                                                        class="absolute z-20 mt-2 w-full rounded-2xl border border-brand-ink/10 bg-white p-2 shadow-xl shadow-brand-ink/10"
                                                    >
                                                        <div class="relative">
                                                            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-moss" aria-hidden="true" />
                                                            <input
                                                                x-ref="repoSearch"
                                                                x-model="search"
                                                                type="text"
                                                                placeholder="{{ __('Filter repositories…') }}"
                                                                class="block w-full rounded-xl border border-brand-ink/15 bg-white py-2 pl-9 pr-3 text-sm text-brand-ink placeholder:text-brand-mist focus:border-brand-ink focus:outline-none focus:ring-1 focus:ring-brand-ink"
                                                            />
                                                        </div>

                                                        <div class="mt-2 max-h-64 space-y-1 overflow-y-auto overscroll-contain pr-1">
                                                            @foreach ($availableRepositories as $repository)
                                                                @php
                                                                    $repoUrl = (string) ($repository['url'] ?? '');
                                                                    $repoLabel = (string) ($repository['label'] ?? '');
                                                                    $repoLabelLower = Str::lower($repoLabel);
                                                                    $repoUrlLower = Str::lower($repoUrl);
                                                                    $isSelectedRepo = $repository_selection === $repoUrl;
                                                                @endphp
                                                                <button
                                                                    type="button"
                                                                    role="option"
                                                                    wire:click="$set('repository_selection', '{{ $repoUrl }}')"
                                                                    x-on:click="open = false; search = ''"
                                                                    x-show="'{{ $repoLabelLower }}'.includes(search.toLowerCase()) || '{{ $repoUrlLower }}'.includes(search.toLowerCase())"
                                                                    aria-selected="{{ $isSelectedRepo ? 'true' : 'false' }}"
                                                                    class="block w-full rounded-lg px-3 py-2 text-left font-mono text-sm transition {{ $isSelectedRepo ? 'bg-brand-sand/40 text-brand-ink ring-1 ring-brand-ink/15' : 'text-brand-ink hover:bg-brand-sand/30' }}"
                                                                >
                                                                    {{ $repoLabel }}
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                </div>
                                            @else
                                                <p class="mt-1.5 flex items-start gap-1.5 rounded-xl border border-brand-ink/10 bg-brand-cream/70 px-3 py-2.5 text-xs text-brand-moss">
                                                    <x-heroicon-o-information-circle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    <span>{{ __('No repositories found for this account. Switch to “Paste a URL”, or pick another account.') }}</span>
                                                </p>
                                            @endif
                                            <x-input-error :messages="$errors->get('repository_selection')" class="mt-2" />
                                        </div>
                                    @else
                                        <div>
                                            <x-input-label for="git-repository-url" :value="__('Repository URL')" required />
                                            <x-text-input
                                                id="git-repository-url"
                                                type="text"
                                                wire:model="git_repository_url"
                                                autocomplete="off"
                                                class="mt-1.5 font-mono"
                                                placeholder="git@github.com:acme/app.git"
                                            />
                                            <x-input-error :messages="$errors->get('git_repository_url')" class="mt-2" />
                                        </div>
                                    @endif

                                    <div>
                                        <x-input-label :value="__('Ref to deploy')" required />
                                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                            <span @class([
                                                'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-sm',
                                                'border-violet-200 bg-violet-50 text-violet-900' => ($git_ref_kind ?? 'branch') === 'branch',
                                                'border-amber-200 bg-amber-50 text-amber-900' => $git_ref_kind === 'tag',
                                                'border-sky-200 bg-sky-50 text-sky-900' => $git_ref_kind === 'commit',
                                            ])>
                                                <span class="text-[10px] font-semibold uppercase tracking-wide">{{ match ($git_ref_kind ?? 'branch') {
                                                    'tag' => __('Tag'),
                                                    'commit' => __('Commit'),
                                                    default => __('Branch'),
                                                } }}</span>
                                                <span>{{ $git_ref_kind === 'commit'
                                                    ? \Illuminate\Support\Str::limit($git_branch, 12, '')
                                                    : $git_branch }}</span>
                                            </span>
                                            <button type="button" wire:click="openRefPicker"
                                                wire:loading.attr="disabled" wire:target="openRefPicker,setRepoRefTab,updatedRepoRefSearch"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-progress disabled:opacity-60">
                                                <span wire:loading.remove wire:target="openRefPicker" class="inline-flex items-center gap-1.5">
                                                    <x-heroicon-o-arrows-right-left class="h-3.5 w-3.5" aria-hidden="true" />
                                                    {{ __('Change…') }}
                                                </span>
                                                <span wire:loading wire:target="openRefPicker" class="inline-flex items-center gap-1.5">
                                                    <x-spinner size="sm" />
                                                    {{ __('Loading…') }}
                                                </span>
                                            </button>
                                        </div>
                                        <p class="mt-1.5 text-xs text-brand-moss">{{ __('Choose a branch, tag, or specific commit from the connected repository.') }}</p>
                                        <x-input-error :messages="$errors->get('git_branch')" class="mt-2" />

                                        @if ($repo_ref_picker_open)
                                            @include('livewire.sites.partials._repository-ref-picker')
                                        @endif
                                    </div>
                                    @if (($selectedTile['kind'] ?? '') === 'preset' && ($selectedTile['framework'] ?? '') !== '')
                                        <p class="flex items-start gap-1.5 text-xs text-brand-moss">
                                            <x-heroicon-o-information-circle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                            <span>{{ __('Web directory and deploy defaults for :fw are pre-filled. Adjust them later in site settings if needed.', ['fw' => $selectedTile['label']]) }}</span>
                                        </p>
                                    @endif
                                @endif
                            </div>

                            <div class="flex flex-col-reverse gap-3 rounded-b-2xl border-t border-brand-ink/10 bg-brand-cream/60 px-6 py-4 sm:flex-row sm:items-center sm:justify-end">
                                <a href="{{ route('servers.sites', $server) }}" wire:navigate
                                    wire:loading.class="pointer-events-none opacity-60" wire:target="run"
                                    class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                                    {{ __('Cancel') }}
                                </a>
                                <x-primary-button
                                    wire:loading.attr="disabled"
                                    wire:target="run"
                                    class="min-w-[10rem] disabled:cursor-progress disabled:opacity-80"
                                >
                                    <span wire:loading.remove wire:target="run" class="inline-flex items-center gap-2">
                                        @if ($selectedTile['kind'] === 'scaffold')
                                            {{ __('Install :app', ['app' => $selectedTile['label']]) }}
                                        @elseif ($selectedTile['kind'] === 'blank')
                                            {{ __('Leave it blank') }}
                                        @elseif ($selectedTile['kind'] === 'static')
                                            {{ __('Set up static site') }}
                                        @else
                                            {{ __('Connect & deploy') }}
                                        @endif
                                        <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <span wire:loading wire:target="run" class="inline-flex items-center gap-2">
                                        <x-spinner size="sm" variant="cream" />
                                        @if ($selectedTile['kind'] === 'scaffold')
                                            {{ __('Starting installer…') }}
                                        @else
                                            {{ __('Setting up…') }}
                                        @endif
                                    </span>
                                </x-primary-button>
                            </div>
                        </div>
                    </form>
                @endif
            </main>
        </div>
    </div>
</div>
