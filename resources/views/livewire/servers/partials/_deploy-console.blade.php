{{--
    Deploy console slide-over — shared by servers index + workspace pages.
    Host needs WatchesSiteDeploys ($this->watchedRows / watchedInProgress).
    Opens via the `deploy-console-open` window event from watchDeploys().
--}}
@php
    $keyPrefix ??= 'workspace';
    $emptyMessage ??= __('Hit Deploy or Sync on a site to watch it here.');
    $watchedCount = count($this->watchedRows);
    $finishedCount = collect($this->watchedRows)->filter(fn (array $r): bool => ! ($r['in_progress'] ?? false))->count();
    $progressPct = $watchedCount > 0 ? (int) round(($finishedCount / $watchedCount) * 100) : 0;
@endphp

<div
    x-data="{
        open: false,
        lockScroll() {
            document.body.classList.add('overflow-y-hidden');
        },
        unlockScroll() {
            document.body.classList.remove('overflow-y-hidden');
        },
        show() {
            this.open = true;
            this.lockScroll();
        },
        hide() {
            this.open = false;
            this.unlockScroll();
        },
    }"
    x-on:deploy-console-open.window="show()"
    x-on:keydown.escape.window="if (open) hide()"
    x-on:destroy="unlockScroll()"
>
    @if ($watchedCount > 0)
        <button
            type="button"
            x-show="! open"
            x-on:click="show()"
            class="fixed bottom-5 right-5 z-40 inline-flex items-center gap-2.5 rounded-2xl border border-brand-ink/10 bg-brand-ink px-4 py-2.5 text-xs font-semibold text-brand-cream shadow-xl shadow-brand-ink/25 transition hover:bg-brand-forest"
            title="{{ __('Open deploy console') }}"
        >
            @if ($this->watchedInProgress)
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-300 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-amber-400"></span>
                </span>
                <x-spinner variant="cream" size="sm" />
                {{ trans_choice('Deploying :n site|Deploying :n sites', $watchedCount, ['n' => $watchedCount]) }}
            @else
                <x-heroicon-m-check-circle class="h-4 w-4 text-emerald-300" aria-hidden="true" />
                {{ __('Deploys finished') }}
            @endif
        </button>
    @endif

    <div
        x-show="open"
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
            class="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col bg-brand-cream shadow-2xl shadow-brand-ink/30 ring-1 ring-brand-ink/10"
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
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Deploy console') }}</p>
                            <h2 id="deploy-console-title" class="mt-0.5 truncate text-lg font-semibold tracking-tight text-brand-ink">
                                @if ($this->watchedInProgress)
                                    {{ trans_choice('Deploying :n site|Deploying :n sites', $watchedCount, ['n' => $watchedCount]) }}
                                @elseif ($watchedCount > 0)
                                    {{ __('Deploys finished') }}
                                @else
                                    {{ __('Ready to watch') }}
                                @endif
                            </h2>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                @if ($this->watchedInProgress)
                                    {{ __(':done of :total complete · updates live', ['done' => $finishedCount, 'total' => $watchedCount]) }}
                                @elseif ($watchedCount > 0)
                                    {{ trans_choice('{1}:n site shipped|[2,*]:n sites shipped', $watchedCount, ['n' => $watchedCount]) }}
                                @else
                                    {{ __('Launch a Deploy or Sync to stream progress here.') }}
                                @endif
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
            </header>

            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-5 sm:px-6" @if ($this->watchedInProgress) wire:poll.3s @endif>
                @forelse ($this->watchedRows as $row)
                    @include('livewire.sites.partials._deploy-console-row', ['row' => $row, 'keyPrefix' => $keyPrefix])
                @empty
                    <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-brand-ink/15 bg-white px-6 py-14 text-center">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/60 text-brand-moss ring-1 ring-brand-ink/10">
                            <x-heroicon-o-rocket-launch class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No active deploys') }}</p>
                        <p class="mt-1.5 max-w-xs text-xs leading-relaxed text-brand-moss">{{ $emptyMessage }}</p>
                    </div>
                @endforelse
            </div>

            <footer class="shrink-0 border-t border-brand-ink/10 bg-white px-5 py-3 sm:px-6">
                <div class="flex items-center justify-between gap-3 text-[11px] text-brand-moss">
                    @if ($this->watchedInProgress)
                        <span class="inline-flex items-center gap-1.5 font-medium text-brand-ink">
                            <x-spinner size="sm" />
                            {{ __('Streaming live') }}
                        </span>
                        <span>{{ __('Esc to close') }}</span>
                    @elseif ($watchedCount > 0)
                        <span class="inline-flex items-center gap-1.5 font-medium text-emerald-700">
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('All deploys finished') }}
                        </span>
                        <span>{{ __('Esc to close') }}</span>
                    @else
                        <span>{{ __('Waiting for a deploy') }}</span>
                        <span>{{ __('Esc to close') }}</span>
                    @endif
                </div>
            </footer>
        </aside>
    </div>
</div>
