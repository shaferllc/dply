{{-- Setup-in-progress hero — kept dark/theatrical because it's a
     "stop everything" state and intentionally outweighs the chrome. --}}
<section class="relative overflow-hidden rounded-3xl border border-brand-ink/10 bg-brand-ink px-6 py-7 text-brand-cream shadow-[0_30px_90px_rgba(19,28,23,0.18)] sm:px-8 sm:py-8">
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute inset-x-0 top-0 h-px bg-white/10"></div>
        <div class="absolute -right-16 top-1/2 h-40 w-40 -translate-y-1/2 rounded-full bg-brand-sage/20 blur-3xl"></div>
    </div>

    <div class="relative max-w-4xl">
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center gap-2 whitespace-nowrap rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-sand/90">
                <span class="inline-flex h-2 w-2 rounded-full bg-amber-300 shadow-[0_0_0_4px_rgba(252,211,77,0.16)]"></span>
                {{ __('Setup in progress') }}
            </span>
            <span class="inline-flex items-center whitespace-nowrap rounded-full border border-white/10 bg-black/10 px-3 py-1.5 text-xs font-medium text-brand-cream/80">
                {{ __('Workspace unlocks after setup finishes') }}
            </span>
        </div>

        <div class="mt-5">
            <h2 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">
                {{ __('Finish setup before using this server.') }}
            </h2>
            <p class="mt-3 max-w-3xl text-sm leading-relaxed text-brand-cream/80">
                {{ __('Reconnect over SSH, watch live installation output, and re-run setup safely if this server needs another pass before the workspace is unlocked.') }}
            </p>

            <div class="mt-5 flex flex-wrap gap-2 text-xs text-brand-cream/75">
                <span class="inline-flex items-center whitespace-nowrap rounded-md border border-white/10 bg-white/5 px-2.5 py-1">
                    {{ __('Provider') }}: <span class="ml-1.5 font-semibold text-white">{{ $server->provider->label() }}</span>
                </span>
                <span class="inline-flex items-center whitespace-nowrap rounded-md border border-white/10 bg-white/5 px-2.5 py-1">
                    {{ __('IP') }}: <span class="ml-1.5 font-mono font-semibold text-white">{{ $server->ip_address ?? '—' }}</span>
                </span>
                @if ($server->private_ip_address)
                    <span class="inline-flex items-center whitespace-nowrap rounded-md border border-white/10 bg-white/5 px-2.5 py-1">
                        {{ __('Private IP') }}: <span class="ml-1.5 font-mono font-semibold text-white">{{ $server->private_ip_address }}</span>
                    </span>
                @endif
                <span class="inline-flex items-center whitespace-nowrap rounded-md border border-white/10 bg-white/5 px-2.5 py-1">
                    {{ __('Setup') }}: <span class="ml-1.5 font-semibold text-white">{{ ucfirst($server->setup_status ?? __('Pending')) }}</span>
                </span>
            </div>

            <div class="mt-6 max-w-3xl rounded-2xl border border-white/10 bg-white/95 p-5 text-brand-ink shadow-[0_20px_70px_rgba(12,18,15,0.16)]">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Next step') }}</p>
                <p class="mt-1.5 text-base font-semibold tracking-tight text-brand-ink">{{ __('Open the setup journey') }}</p>
                <p class="mt-1 text-sm leading-6 text-brand-moss">
                    {{ __('Watch live progress, inspect current output, and re-run installation from a clean tracked setup task if needed.') }}
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a
                        href="{{ route('servers.journey', $server) }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Open setup journey') }}
                    </a>
                    @if (\App\Jobs\RunSetupScriptJob::shouldDispatch($server))
                        <button
                            type="button"
                            wire:click="rerunSetup"
                            wire:loading.attr="disabled"
                            wire:target="rerunSetup"
                            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="rerunSetup" class="inline-flex items-center gap-2">
                                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Re-run setup') }}
                            </span>
                            <span wire:loading wire:target="rerunSetup" class="inline-flex items-center gap-2 whitespace-nowrap">
                                <x-spinner variant="ink" size="sm" />
                                {{ __('Re-running…') }}
                            </span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
