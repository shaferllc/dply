<x-server-workspace-tab-panel id="snapshots-panel-volumes" labelled-by="snapshots-tab-volumes" panel-class="space-y-8">
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-square-3-stack-3d class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Block storage') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Volume snapshots') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Point-in-time snapshots of attached block-storage volumes, captured through your cloud provider.') }}</p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-10 text-center">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sand/60 text-brand-moss ring-1 ring-brand-ink/10">
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </span>
                <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('Coming soon') }}</p>
                <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                    @if ($volumesSupported)
                        {{ __('Volume snapshots are being wired up for :provider. Use Server images in the meantime to capture the whole machine.', ['provider' => $server->provider?->label() ?? __('this provider')]) }}
                    @else
                        {{ __('Volume snapshots aren’t available for :provider yet. Use Server images to capture the whole machine, including its disks.', ['provider' => $server->provider?->label() ?? __('this provider')]) }}
                    @endif
                </p>
            </div>
        </div>
    </section>
</x-server-workspace-tab-panel>
