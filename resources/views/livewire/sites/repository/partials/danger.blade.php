<section class="space-y-6">
    <div class="dply-card overflow-hidden border-red-200">
        <div class="flex items-start gap-3 border-b border-red-200 bg-red-50/60 px-6 py-5 sm:px-7">
            <x-icon-badge tone="red">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-red-700">{{ __('Danger zone') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Reset this site') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Destructive actions for this site’s application. The site shell — server, domains, testing URL, and certificates — is always kept.') }}</p>
            </div>
        </div>

        <div class="p-6 sm:p-7">
            @if (trim((string) ($site->git_repository_url ?? '')) === '')
                <p class="flex items-start gap-2 rounded-xl border border-brand-ink/10 bg-brand-cream/60 px-4 py-3 text-sm text-brand-moss">
                    <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                    <span>{{ __('No repository is connected to this site yet, so there’s nothing to uninstall. Connect one from the Connection tab.') }}</span>
                </p>
            @else
                @can('update', $site)
                    <div class="rounded-2xl border border-red-200 bg-red-50/50 p-5" x-data="{ confirming: false }">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-red-900">{{ __('Disconnect repository & start over') }}</h3>
                                <p class="mt-1 max-w-xl text-xs leading-relaxed text-red-800/80">
                                    {{ __('Removes the connected repository and wipes the deployed code, releases, and env from the server, returning the site to a blank splash page. Connect a new repository or pick a different app afterwards. This cannot be undone.') }}
                                </p>
                            </div>
                            <div class="shrink-0">
                                <template x-if="!confirming">
                                    <button type="button" x-on:click="confirming = true"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-semibold text-red-800 shadow-sm transition hover:bg-red-100">
                                        <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                        {{ __('Disconnect & start over') }}
                                    </button>
                                </template>
                                <template x-if="confirming">
                                    <div class="inline-flex items-center gap-2">
                                        <button type="button" x-on:click="confirming = false"
                                            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                                        <button type="button" wire:click="disconnectAndStartOver"
                                            wire:loading.attr="disabled" wire:target="disconnectAndStartOver"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-red-700 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-red-800 disabled:cursor-progress disabled:opacity-60">
                                            <span wire:loading.remove wire:target="disconnectAndStartOver" class="inline-flex items-center gap-1.5">
                                                <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                                {{ __('Yes, wipe & reset') }}
                                            </span>
                                            <span wire:loading wire:target="disconnectAndStartOver" class="inline-flex items-center gap-1.5">
                                                <x-spinner size="sm" variant="white" />
                                                {{ __('Resetting…') }}
                                            </span>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                @endcan
            @endif
        </div>
    </div>
</section>
