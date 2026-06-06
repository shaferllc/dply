<section class="space-y-6">
    {{-- No overflow-hidden here: the Deploy-ref picker is an absolutely-positioned
         dropdown that must escape the card bounds. Header/footer round their own
         corners instead so the card still looks clipped. --}}
    <div class="dply-card">
        <div class="flex items-start gap-3 rounded-t-2xl border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Source control') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Connection') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Which OAuth account dply uses to read commits, list branches, and provision webhooks.') }}
                </p>
            </div>
        </div>

        <form wire:submit.prevent="saveConnection" class="space-y-4 px-6 py-6 sm:px-7">
            <label class="block text-sm">
                <span class="flex items-center justify-between gap-2">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Linked source-control account') }}</span>
                    <x-connect-provider-link>{{ __('Connect a provider') }} &rarr;</x-connect-provider-link>
                </span>
                <x-select wire:model.live="connectionAccountId">
                    <option value="">{{ __('— Custom / no linked account —') }}</option>
                    @foreach ($connectionAccounts as $account)
                        <option value="{{ $account['id'] }}">{{ $account['label'] }} ({{ $account['provider'] }})</option>
                    @endforeach
                </x-select>
            </label>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Repository URL') }}</span>
                    <x-text-input
                        type="text"
                        wire:model="connectionRepositoryUrl"
                        placeholder="git@github.com:acme/api.git"
                        class="font-mono"
                    />
                </label>
                <div class="relative block text-sm">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Deploy ref') }}</span>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <span @class([
                            'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-sm',
                            'border-violet-200 bg-violet-50 text-violet-900' => ($connectionRefKind ?? 'branch') === 'branch',
                            'border-amber-200 bg-amber-50 text-amber-900' => $connectionRefKind === 'tag',
                            'border-sky-200 bg-sky-50 text-sky-900' => $connectionRefKind === 'commit',
                        ])>
                            <span class="text-[10px] font-semibold uppercase tracking-wide">{{ match ($connectionRefKind ?? 'branch') {
                                'tag' => __('Tag'),
                                'commit' => __('Commit'),
                                default => __('Branch'),
                            } }}</span>
                            <span>{{ $connectionRefKind === 'commit'
                                ? \Illuminate\Support\Str::limit($connectionBranch ?: 'main', 12, '')
                                : ($connectionBranch ?: 'main') }}</span>
                        </span>
                        <button type="button" wire:click="openConnectionRefPicker"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-arrows-right-left class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Change…') }}
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Pick a branch, tag, or specific commit. Saved when you click “Save connection”.') }}</p>

                    @if ($repo_ref_picker_open)
                        {{-- Anchored dropdown: floats over the form instead of pushing
                             the layout. The picker partial closes on outside-click. --}}
                        <div class="absolute left-0 top-full z-30 w-[min(28rem,90vw)]">
                            @include('livewire.sites.partials._repository-ref-picker')
                        </div>
                    @endif
                </div>
            </div>
        </form>

        <div class="flex justify-end rounded-b-2xl border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <button
                type="button"
                wire:click="saveConnection"
                wire:loading.attr="disabled"
                wire:target="saveConnection"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
            >
                <x-heroicon-o-check class="h-4 w-4" />
                <span wire:loading.remove wire:target="saveConnection">{{ __('Save connection') }}</span>
                <span wire:loading wire:target="saveConnection">{{ __('Saving…') }}</span>
            </button>
        </div>
    </div>

    @if (($connectionRepositoriesAccountTotal ?? 0) > 0)
        <div class="dply-card overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-rectangle-group class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                        <div class="mt-0.5 flex items-center gap-2">
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Repositories on this account') }}</h2>
                            <span class="inline-flex items-center rounded-full bg-brand-ink/5 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ $connectionRepositoriesAccountTotal }}</span>
                        </div>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('One-click swap to a different repository under the linked account. The deploy branch resets to the target repo\'s default.') }}</p>
                    </div>
                </div>
                <div class="relative w-full shrink-0 sm:w-64">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="repoSearch"
                        placeholder="{{ __('Filter repositories…') }}"
                        class="w-full rounded-lg border border-brand-ink/15 bg-white py-2 pl-9 pr-3 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                    />
                </div>
            </div>

            @if (empty($connectionRepositories))
                <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                    {{ __('No repositories match “:term”.', ['term' => $repoSearch]) }}
                    <button type="button" wire:click="$set('repoSearch', '')" class="ml-1 font-semibold text-brand-forest hover:underline">{{ __('Clear filter') }}</button>
                </div>
            @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($connectionRepositories as $repo)
                    @php($isCurrent = (string) ($repo['url'] ?? '') === $currentRepositoryUrl)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 sm:px-8" wire:key="repo-{{ $repo['url'] }}">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm text-brand-ink">{{ $repo['label'] }}</p>
                            @if (! empty($repo['url']))
                                <p class="mt-0.5 truncate text-[11px] text-brand-mist">{{ $repo['url'] }}</p>
                            @endif
                        </div>
                        @if ($isCurrent)
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-emerald-900">{{ __('current') }}</span>
                        @else
                            <button
                                type="button"
                                wire:click="askSwitchRepository('{{ addslashes($repo['url']) }}', '{{ addslashes($repo['branch'] ?? 'main') }}', '{{ addslashes($repo['label']) }}')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-arrow-right class="h-3.5 w-3.5" />
                                {{ __('Switch to this repo') }}
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
            @endif

            @if (($connectionRepositoriesPages ?? 1) > 1)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-3 sm:px-8">
                    <p class="text-xs text-brand-moss">
                        {{ __('Page :page of :pages', ['page' => $connectionRepositoriesPage, 'pages' => $connectionRepositoriesPages]) }}
                    </p>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="$set('repoPage', {{ max(1, $connectionRepositoriesPage - 1) }})"
                            @disabled($connectionRepositoriesPage <= 1)
                            class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <x-heroicon-o-chevron-left class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Prev') }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('repoPage', {{ min($connectionRepositoriesPages, $connectionRepositoriesPage + 1) }})"
                            @disabled($connectionRepositoriesPage >= $connectionRepositoriesPages)
                            class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {{ __('Next') }}
                            <x-heroicon-o-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Quick deploy webhook moved out — it now lives as its own card in
         Deployments → Settings (rendered by repository/partials/webhook.blade.php
         via lockedTab="webhook"). The methods + state still live on this
         Repository Livewire component, so wiring is unchanged. --}}

    {{-- "Disconnect repository & start over" lives on the Danger tab
         (repository/partials/danger.blade.php). --}}

    {{-- Switch-repo confirmation. Replaces the native browser confirm() so the
         "deploy branch will reset" warning reads in-product. --}}
    @if ($pendingRepoSwitch !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-brand-ink/40 p-4" wire:click.self="cancelSwitchRepository">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-800">
                        <x-heroicon-o-arrow-path-rounded-square class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Switch repository?') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Switch this app to :label? The deploy branch will reset to :branch.', [
                                'label' => $pendingRepoSwitch['label'],
                                'branch' => $pendingRepoSwitch['branch'],
                            ]) }}
                        </p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <button type="button" wire:click="cancelSwitchRepository"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button"
                        wire:click="confirmSwitchRepository"
                        wire:loading.attr="disabled"
                        wire:target="confirmSwitchRepository"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                    >
                        <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
                        <span wire:loading.remove wire:target="confirmSwitchRepository">{{ __('Switch repository') }}</span>
                        <span wire:loading wire:target="confirmSwitchRepository">{{ __('Switching…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</section>
