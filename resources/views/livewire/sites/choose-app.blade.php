<div>
    @php($selectedTile = collect($tiles)->firstWhere('key', $selected))

    <div class="py-10">
        <div class="dply-page-shell">
            <div class="relative">
                {{-- Decorative brand mesh wash behind the hero. --}}
                <div class="pointer-events-none absolute inset-x-0 -top-16 -z-10 h-80 bg-mesh-brand opacity-90"></div>

                {{-- Hero --}}
                <div class="mx-auto max-w-2xl text-center">
                    <span class="inline-flex items-center gap-2 rounded-full border border-brand-sage/25 bg-white/70 px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-brand-forest shadow-sm backdrop-blur">
                        <span class="inline-flex h-1.5 w-1.5 rounded-full bg-brand-gold"></span>
                        {{ __('Step 2 of 2 · Choose an application') }}
                    </span>
                    <h1 class="mt-4 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">
                        {{ __('What runs on :site?', ['site' => $site->name]) }}
                    </h1>
                    <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-brand-moss sm:text-base">
                        {{ __('Install a fresh app, deploy an existing repository, or start blank. WordPress and Laravel set themselves up — database included.') }}
                    </p>
                </div>

                {{-- Tile grid --}}
                <div class="mx-auto mt-10 max-w-5xl">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($tiles as $tile)
                            @php($isSelected = $selected === $tile['key'])
                            @php($isInstaller = ($tile['kind'] ?? '') === 'scaffold')
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
                    <form wire:submit="run" class="mx-auto mt-6 max-w-5xl">
                        <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-md shadow-brand-ink/5">
                            <div class="flex items-center gap-3 border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-4">
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
                                        <span>{{ __('An empty PHP site is provisioned and serves a default page. Return to this picker any time to install a real application.') }}</span>
                                    </p>
                                @else
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
                                    @endif

                                    @if ($repo_source === 'provider' && count($linkedSourceControlAccounts) > 0)
                                        <div>
                                            <x-input-label for="sc-account" :value="__('Account')" required />
                                            <select id="sc-account" wire:model.live="source_control_account_id" class="dply-input mt-1.5">
                                                @foreach ($linkedSourceControlAccounts as $account)
                                                    <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$errors->get('source_control_account_id')" class="mt-2" />
                                        </div>
                                        <div>
                                            <x-input-label for="sc-repo" :value="__('Repository')" required />
                                            @if (count($availableRepositories) > 0)
                                                <select id="sc-repo" wire:model.live="repository_selection" class="dply-input mt-1.5">
                                                    @foreach ($availableRepositories as $repository)
                                                        <option value="{{ $repository['url'] }}">{{ $repository['label'] }}</option>
                                                    @endforeach
                                                </select>
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
                                        <x-input-label for="git-branch" :value="__('Branch')" required />
                                        <x-text-input
                                            id="git-branch"
                                            type="text"
                                            wire:model="git_branch"
                                            autocomplete="off"
                                            class="mt-1.5 font-mono"
                                        />
                                        <x-input-error :messages="$errors->get('git_branch')" class="mt-2" />
                                    </div>
                                    @if (($selectedTile['kind'] ?? '') === 'preset' && ($selectedTile['framework'] ?? '') !== '')
                                        <p class="flex items-start gap-1.5 text-xs text-brand-moss">
                                            <x-heroicon-o-information-circle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                            <span>{{ __('Web directory and deploy defaults for :fw are pre-filled. Adjust them later in site settings if needed.', ['fw' => $selectedTile['label']]) }}</span>
                                        </p>
                                    @endif
                                @endif
                            </div>

                            <div class="flex flex-col-reverse gap-3 border-t border-brand-ink/10 bg-brand-cream/60 px-6 py-4 sm:flex-row sm:items-center sm:justify-end">
                                <a href="{{ route('servers.sites', $server) }}" wire:navigate
                                    class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                                    {{ __('Cancel') }}
                                </a>
                                <x-primary-button>
                                    @if ($selectedTile['kind'] === 'scaffold')
                                        {{ __('Install :app', ['app' => $selectedTile['label']]) }}
                                    @elseif ($selectedTile['kind'] === 'blank')
                                        {{ __('Create blank site') }}
                                    @else
                                        {{ __('Create site') }}
                                    @endif
                                    <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
                                </x-primary-button>
                            </div>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
