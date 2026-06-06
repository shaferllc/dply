<div>
    @php
        $selectedTile = collect($tiles)->firstWhere('key', $selected);
        // A "coming soon" tile (deep-linked ?app=) must never open the config form.
        if ($selectedTile && ($selectedTile['coming_soon'] ?? false)) {
            $selectedTile = null;
        }
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
                                $isComingSoon = $tile['coming_soon'] ?? false;
                            @endphp
                            <button
                                type="button"
                                @unless ($isComingSoon) wire:click="selectTile('{{ $tile['key'] }}')" @endunless
                                @disabled($isComingSoon)
                                @class([
                                    'group relative flex items-start gap-3 rounded-2xl border p-4 text-left transition-all',
                                    'cursor-not-allowed opacity-70 border-brand-ink/10 bg-brand-sand/15' => $isComingSoon,
                                    'border-brand-forest bg-white shadow-md shadow-brand-ink/5 ring-2 ring-brand-sage/30' => $isSelected && ! $isComingSoon,
                                    'border-brand-ink/10 bg-white/80 shadow-sm hover:-translate-y-0.5 hover:border-brand-ink/20 hover:shadow-md' => ! $isSelected && ! $isComingSoon,
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
                                        @if ($isComingSoon)
                                            <span class="inline-flex items-center rounded-full bg-brand-ink/[0.06] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-mist">{{ __('Coming soon') }}</span>
                                        @elseif ($isInstaller)
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
                                    @include('livewire.sites.partials._git-repository-configurator', ['idPrefix' => 'choose'])

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
