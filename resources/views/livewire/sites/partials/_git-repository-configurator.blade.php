{{--
    Shared "Configure Git repository" picker: source toggle (connected provider /
    paste a URL) + account select + searchable repository dropdown + manual URL.

    Backed by the App\Livewire\Concerns\Sites\ConfiguresGitRepository trait — the
    host component must `use` it (and RefreshesLinkedSourceControlAccounts) so the
    property names below resolve.

    Deliberately does NOT render the "Ref to deploy" block: hosts differ on whether
    a ref picker exists and how it opens, so each renders its own ref UI after this
    include.

    Params:
      - $idPrefix       string  unique prefix for input ids (default 'gitcfg')
      - $showConnectLink bool   render the "Connect a provider" link (default true)
      - $required       bool    mark the account/repo/URL labels required (default true)
--}}
@php
    $idPrefix = $idPrefix ?? 'gitcfg';
    $showConnectLink = $showConnectLink ?? true;
    $required = $required ?? true;
@endphp

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
    @if ($showConnectLink)
        <x-connect-provider-link>{{ count($linkedSourceControlAccounts) > 0
            ? __('Connect another account')
            : __('Connect a provider') }} &rarr;</x-connect-provider-link>
    @endif
</div>

@if ($repo_source === 'provider' && count($linkedSourceControlAccounts) > 0)
    <div>
        <div class="flex items-center gap-2">
            <x-input-label for="{{ $idPrefix }}-account" :value="__('Account')" :required="$required" />
            <span wire:loading wire:target="source_control_account_id" class="inline-flex items-center gap-1 text-[11px] font-medium text-brand-moss">
                <x-spinner size="sm" />
                {{ __('Loading repositories…') }}
            </span>
        </div>
        <select id="{{ $idPrefix }}-account" wire:model.live="source_control_account_id"
            wire:loading.attr="disabled" wire:target="source_control_account_id"
            class="dply-input mt-1.5 disabled:cursor-progress disabled:opacity-60">
            @foreach ($linkedSourceControlAccounts as $account)
                <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('source_control_account_id')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="{{ $idPrefix }}-repo" :value="__('Repository')" :required="$required" />
        @if (count($availableRepositories) > 0)
            @php
                $selectedRepository = collect($availableRepositories)->firstWhere('url', $repository_selection);
            @endphp
            <div
                x-data="{
                    open: false,
                    search: '',
                    active: 0,
                    repos: @js(collect($availableRepositories)->map(fn ($r) => ['label' => (string) ($r['label'] ?? ''), 'url' => (string) ($r['url'] ?? '')])->values()),
                    get filtered() {
                        const q = this.search.trim().toLowerCase();
                        if (q === '') return this.repos;
                        return this.repos.filter(r => r.label.toLowerCase().includes(q) || r.url.toLowerCase().includes(q));
                    },
                    toggle() { this.open ? this.close() : this.openList(); },
                    openList() { this.open = true; this.active = 0; this.$nextTick(() => this.$refs.repoSearch && this.$refs.repoSearch.focus()); },
                    close() { this.open = false; this.search = ''; },
                    move(delta) {
                        const n = this.filtered.length;
                        if (n === 0) return;
                        this.active = (this.active + delta + n) % n;
                        this.$nextTick(() => { const el = this.$refs.list && this.$refs.list.querySelector('[data-active=true]'); el && el.scrollIntoView({ block: 'nearest' }); });
                    },
                    chooseActive() { const r = this.filtered[this.active]; if (r) this.choose(r.url); },
                    choose(url) { $wire.set('repository_selection', url); this.close(); this.$nextTick(() => this.$refs.trigger && this.$refs.trigger.focus()); },
                }"
                class="relative mt-1.5"
                wire:loading.class="opacity-60 pointer-events-none" wire:target="source_control_account_id"
                x-on:keydown.escape.window="close()"
            >
                <button
                    id="{{ $idPrefix }}-repo"
                    x-ref="trigger"
                    type="button"
                    x-on:click="toggle()"
                    x-on:keydown.arrow-down.prevent="openList()"
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
                    x-on:click.outside="close()"
                    role="listbox"
                    class="absolute z-20 mt-2 w-full rounded-2xl border border-brand-ink/10 bg-white p-2 shadow-xl shadow-brand-ink/10"
                >
                    <div class="relative">
                        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-moss" aria-hidden="true" />
                        <input
                            x-ref="repoSearch"
                            x-model="search"
                            x-on:input="active = 0"
                            x-on:keydown.arrow-down.prevent="move(1)"
                            x-on:keydown.arrow-up.prevent="move(-1)"
                            x-on:keydown.enter.prevent="chooseActive()"
                            type="text"
                            placeholder="{{ __('Filter repositories…') }}"
                            class="block w-full rounded-xl border border-brand-ink/15 bg-white py-2 pl-9 pr-3 text-sm text-brand-ink placeholder:text-brand-mist focus:border-brand-ink focus:outline-none focus:ring-1 focus:ring-brand-ink"
                        />
                    </div>

                    <div x-ref="list" class="mt-2 max-h-64 space-y-1 overflow-y-auto overscroll-contain pr-1">
                        <template x-for="(repo, i) in filtered" :key="repo.url">
                            <button
                                type="button"
                                role="option"
                                x-on:click="choose(repo.url)"
                                x-on:mousemove="active = i"
                                x-bind:data-active="(i === active).toString()"
                                x-bind:aria-selected="(repo.url === $wire.repository_selection).toString()"
                                x-bind:class="{
                                    'bg-brand-sage/15 ring-1 ring-brand-sage/30': i === active,
                                    'bg-brand-sand/40 ring-1 ring-brand-ink/15': repo.url === $wire.repository_selection && i !== active,
                                    'hover:bg-brand-sand/30': i !== active && repo.url !== $wire.repository_selection,
                                }"
                                class="block w-full rounded-lg px-3 py-2 text-left font-mono text-sm text-brand-ink transition"
                                x-text="repo.label"
                            ></button>
                        </template>
                        <p x-show="filtered.length === 0" class="px-3 py-2 text-xs text-brand-moss">{{ __('No repositories match your filter.') }}</p>
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
        <x-input-label for="{{ $idPrefix }}-url" :value="__('Repository URL')" :required="$required" />
        <x-text-input
            id="{{ $idPrefix }}-url"
            type="text"
            wire:model.live.debounce.500ms="git_repository_url"
            autocomplete="off"
            class="mt-1.5 font-mono"
            placeholder="git@github.com:acme/app.git"
        />
        <x-input-error :messages="$errors->get('git_repository_url')" class="mt-2" />
    </div>
@endif
