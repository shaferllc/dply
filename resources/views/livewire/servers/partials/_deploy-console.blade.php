{{-- Deploy console slide-over: opens when Deploy / Sync is launched so the
     queued deploy(s) can be watched live without leaving the page. Host-agnostic
     — relies only on the WatchesSiteDeploys trait's $this->watchedRows /
     watchedInProgress, so any component using that trait can include it. The
     `deploy-console-open` window event is dispatched by watchDeploys(). --}}
<div x-data="{ deployConsoleOpen: false }" x-on:deploy-console-open.window="deployConsoleOpen = true">
    @if (count($this->watchedRows) > 0)
        <button
            type="button"
            x-show="!deployConsoleOpen"
            x-on:click="deployConsoleOpen = true"
            class="fixed bottom-4 left-4 z-40 inline-flex items-center gap-2 rounded-full border border-brand-ink/10 bg-white px-3.5 py-2 text-xs font-semibold text-brand-ink shadow-lg shadow-brand-ink/15 transition hover:bg-brand-sand/40"
            title="{{ __('Open deploy console') }}"
        >
            @if ($this->watchedInProgress)
                <x-spinner size="sm" />
                {{ trans_choice('Deploying :n site|Deploying :n sites', count($this->watchedRows), ['n' => count($this->watchedRows)]) }}
            @else
                <x-heroicon-m-check-circle class="h-4 w-4 text-emerald-600" />
                {{ __('Deploys finished') }}
            @endif
        </button>
    @endif

    <div x-show="deployConsoleOpen" x-cloak class="fixed inset-0 z-50" style="display: none;">
        <div class="absolute inset-0 bg-brand-ink/40" x-on:click="deployConsoleOpen = false" x-transition.opacity></div>
        <div
            class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
        >
            <div class="flex items-center justify-between border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy console') }}</p>
                    <p class="truncate text-sm font-semibold text-brand-ink">
                        @if ($this->watchedInProgress)
                            {{ trans_choice('Deploying :n site|Deploying :n sites', count($this->watchedRows), ['n' => count($this->watchedRows)]) }}
                        @else
                            {{ trans_choice('{0}No deploys yet|{1}:n deploy|[2,*]:n deploys', count($this->watchedRows), ['n' => count($this->watchedRows)]) }}
                        @endif
                    </p>
                </div>
                <button type="button" x-on:click="deployConsoleOpen = false" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-2 overflow-y-auto px-5 py-4" @if ($this->watchedInProgress) wire:poll.3s @endif>
                @forelse ($this->watchedRows as $row)
                    @include('livewire.sites.partials._deploy-console-row', ['row' => $row, 'keyPrefix' => 'workspace'])
                @empty
                    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-10 text-center text-sm text-brand-moss">
                        {{ __('Hit Deploy or Sync on a site to watch it here.') }}
                    </div>
                @endforelse
            </div>

            <div class="border-t border-brand-ink/10 bg-brand-sand/20 px-5 py-3 text-center text-[11px] text-brand-moss">
                @if ($this->watchedInProgress)
                    <span class="inline-flex items-center gap-1.5"><x-spinner size="sm" /> {{ __('Deploying — this updates live.') }}</span>
                @elseif (count($this->watchedRows) > 0)
                    {{ __('All deploys finished.') }}
                @endif
            </div>
        </div>
    </div>
</div>
