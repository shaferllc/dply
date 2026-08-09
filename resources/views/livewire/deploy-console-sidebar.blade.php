{{--
    Global deploy-status sidebar (app shell). Launcher is the floating dock
    "Deploys" chip. Kickoffs from fleet surfaces dispatch deploy-console-focus.
--}}
@php
    $watchedCount = count($this->watchedRows);
    $finishedCount = collect($this->watchedRows)->filter(fn (array $r): bool => ! ($r['in_progress'] ?? false))->count();
    $progressPct = $watchedCount > 0 ? (int) round(($finishedCount / $watchedCount) * 100) : 0;
    $watchingBatch = $this->watchingBatch;

    // Prefer an in-progress row for the header context strip; otherwise the sole
    // watched site. Keeps server/branch/commit visible while monitoring a kickoff.
    $focusRow = collect($this->watchedRows)->first(fn (array $r): bool => (bool) ($r['in_progress'] ?? false))
        ?? ($watchedCount === 1 ? ($this->watchedRows[0] ?? null) : null);
@endphp

<div
    x-data="{
        drawerOpen: false,
        lockScroll() {
            document.body.classList.add('overflow-y-hidden');
        },
        unlockScroll() {
            document.body.classList.remove('overflow-y-hidden');
        },
        show() {
            if (this.drawerOpen) {
                return;
            }
            this.drawerOpen = true;
            this.lockScroll();
            window.dispatchEvent(new CustomEvent('dply-deploy-console-opened'));
        },
        hide() {
            if (! this.drawerOpen) {
                return;
            }
            this.drawerOpen = false;
            this.unlockScroll();
            window.dispatchEvent(new CustomEvent('dply-deploy-console-closed'));
        },
        init() {
            window.addEventListener('dply-open-deploy-status', () => {
                this.$wire.openBrowse();
            });
        },
    }"
    x-on:dply-deploy-console-open.window="show()"
    x-on:keydown.escape.window="if (drawerOpen) hide()"
    x-on:destroy="unlockScroll()"
>
    <div
        x-show="drawerOpen"
        x-cloak
        class="fixed inset-0 z-50"
        style="display: none;"
        role="dialog"
        aria-modal="true"
        aria-labelledby="deploy-console-title"
    >
        <div
            class="absolute inset-0 bg-brand-ink/50 backdrop-blur-[2px]"
            x-on:click="hide()"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
        ></div>

        <aside
            class="absolute inset-y-0 right-0 flex w-full max-w-[560px] flex-col bg-brand-cream shadow-2xl shadow-brand-ink/30 ring-1 ring-brand-ink/10 sm:w-[560px]"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
        >
            <header class="shrink-0 border-b border-brand-ink/10 bg-white px-5 pb-4 pt-5 sm:px-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-ink text-brand-cream shadow-sm">
                            <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Deploy console') }}</p>
                            <h2 id="deploy-console-title" class="mt-0.5 truncate text-lg font-semibold tracking-tight text-brand-ink">
                                @if ($this->watchedInProgress)
                                    {{ trans_choice('Deploying :n site|Deploying :n sites', $watchedCount, ['n' => $watchedCount]) }}
                                @elseif ($watchingBatch && $watchedCount > 0)
                                    {{ __('Deploys finished') }}
                                @elseif ($watchedCount > 0)
                                    {{ __('Active & recent') }}
                                @else
                                    {{ __('No recent deploys') }}
                                @endif
                            </h2>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                @if ($this->watchedInProgress)
                                    {{ __(':done of :total complete · updates live', ['done' => $finishedCount, 'total' => $watchedCount]) }}
                                @elseif ($watchingBatch && $watchedCount > 0)
                                    {{ trans_choice('{1}:n site shipped|[2,*]:n sites shipped', $watchedCount, ['n' => $watchedCount]) }}
                                @elseif ($watchedCount > 0)
                                    {{ __('In-progress and recent deploys across this workspace.') }}
                                @else
                                    {{ __('Launch a Deploy or Sync to stream progress here.') }}
                                @endif
                            </p>
                            <p class="mt-1.5">
                                <x-docs-link slug="sites-and-deploy" class="text-xs">
                                    {{ __('How deploys work') }}
                                </x-docs-link>
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        x-on:click="hide()"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-brand-ink/10 bg-white text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink"
                    >
                        <span class="sr-only">{{ __('Close') }}</span>
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                @if (is_array($focusRow) && ($focusRow['server'] || $focusRow['branch'] || $focusRow['short_sha'] || ($focusRow['in_progress'] ?? false)))
                    <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/25 px-3.5 py-3">
                        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">
                            @if ($focusRow['in_progress'] ?? false)
                                {{ __('Deploying now') }}
                            @else
                                {{ __('Deploy target') }}
                            @endif
                        </p>
                        <dl class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @if ($focusRow['server'] || ($focusRow['name'] ?? null))
                                <div class="min-w-0">
                                    <dt class="text-2xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Server') }}</dt>
                                    <dd class="mt-0.5 truncate text-xs font-semibold text-brand-ink">
                                        {{ $focusRow['server'] ?? '—' }}
                                        @if ($focusRow['server_ip'] ?? null)
                                            <span class="font-mono font-normal text-brand-moss">· {{ $focusRow['server_ip'] }}</span>
                                        @endif
                                    </dd>
                                    @if ($watchedCount > 1 && ($focusRow['name'] ?? null))
                                        <dd class="mt-0.5 truncate text-xs text-brand-moss">{{ __('Site: :name', ['name' => $focusRow['name']]) }}</dd>
                                    @elseif ($watchedCount === 1 && ($focusRow['name'] ?? null) && ($focusRow['server'] ?? null) !== ($focusRow['name'] ?? null))
                                        <dd class="mt-0.5 truncate text-xs text-brand-moss">{{ $focusRow['name'] }}</dd>
                                    @endif
                                </div>
                            @endif
                            <div class="min-w-0">
                                <dt class="text-2xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Branch & commit') }}</dt>
                                <dd class="mt-0.5 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-ink">
                                    @if ($focusRow['branch'] ?? null)
                                        <span class="inline-flex items-center gap-1 font-mono font-semibold">
                                            <x-heroicon-o-tag class="h-3 w-3 text-brand-mist" aria-hidden="true" />
                                            {{ $focusRow['branch'] }}
                                        </span>
                                    @else
                                        <span class="text-brand-mist">{{ __('Branch unknown') }}</span>
                                    @endif
                                    @if ($focusRow['short_sha'] ?? null)
                                        <span class="text-brand-mist">·</span>
                                        @if ($focusRow['commit_url'] ?? null)
                                            <a
                                                href="{{ $focusRow['commit_url'] }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center gap-1 font-mono font-semibold text-brand-forest hover:underline"
                                                title="{{ $focusRow['git_sha'] }}"
                                            >
                                                {{ $focusRow['short_sha'] }}
                                                <x-heroicon-m-arrow-top-right-on-square class="h-3 w-3 opacity-70" aria-hidden="true" />
                                            </a>
                                        @else
                                            <span class="font-mono font-semibold" title="{{ $focusRow['git_sha'] }}">{{ $focusRow['short_sha'] }}</span>
                                        @endif
                                    @elseif ($focusRow['in_progress'] ?? false)
                                        <span class="text-brand-mist">· {{ __('Commit pending…') }}</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endif

                @if ($watchedCount > 0)
                    <div class="mt-4" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progressPct }}" aria-label="{{ __('Deploy progress') }}">
                        <div class="h-1.5 overflow-hidden rounded-full bg-brand-ink/10">
                            <div
                                @class([
                                    'h-full rounded-full transition-all duration-500',
                                    'bg-brand-sage' => $this->watchedInProgress,
                                    'bg-emerald-500' => ! $this->watchedInProgress,
                                ])
                                style="width: {{ $progressPct }}%"
                            ></div>
                        </div>
                    </div>
                @endif

                @if ($watchingBatch && ! $this->watchedInProgress && $watchedCount > 0)
                    <div class="mt-3 flex justify-end">
                        <button
                            type="button"
                            wire:click="showRecent"
                            class="text-xs font-semibold text-brand-forest hover:underline"
                        >
                            {{ __('View active & recent') }}
                        </button>
                    </div>
                @endif
            </header>

            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-5 sm:px-6" @if ($this->watchedInProgress) wire:poll.3s @endif>
                @forelse ($this->watchedRows as $row)
                    @include('livewire.sites.partials._deploy-console-row', ['row' => $row, 'keyPrefix' => 'global'])
                @empty
                    <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-brand-ink/15 bg-white px-6 py-14 text-center">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/60 text-brand-moss ring-1 ring-brand-ink/10">
                            <x-heroicon-o-rocket-launch class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No deploys to show') }}</p>
                        <p class="mt-1.5 max-w-xs text-xs leading-relaxed text-brand-moss">
                            {{ __('Hit Deploy or Sync on a server or site — progress streams here from any page.') }}
                        </p>
                    </div>
                @endforelse
            </div>

            <footer class="shrink-0 border-t border-brand-ink/10 bg-white px-5 py-3 sm:px-6">
                <div class="flex items-center justify-between gap-3 text-xs text-brand-moss">
                    <div class="flex min-w-0 items-center gap-3">
                        @if ($this->watchedInProgress)
                            <span class="inline-flex items-center gap-1.5 font-medium text-brand-ink">
                                <x-spinner size="sm" />
                                {{ __('Streaming live') }}
                            </span>
                        @elseif ($watchingBatch && $watchedCount > 0)
                            <span class="inline-flex items-center gap-1.5 font-medium text-emerald-700">
                                <x-heroicon-m-check-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('All deploys finished') }}
                            </span>
                        @elseif ($watchedCount > 0)
                            <span>{{ __('Workspace deploy status') }}</span>
                        @else
                            <span>{{ __('Waiting for a deploy') }}</span>
                        @endif

                        @if ($this->finishedHistoryCount > 0)
                            <button
                                type="button"
                                wire:click="openClearFinishedConfirm"
                                class="font-semibold text-brand-forest hover:underline"
                            >
                                {{ __('Dismiss finished') }}
                            </button>
                        @endif
                    </div>
                    <span class="shrink-0">{{ __('Esc to close') }}</span>
                </div>
            </footer>
        </aside>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
