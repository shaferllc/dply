<x-server-workspace-tab-panel id="snapshots-panel-volumes" labelled-by="snapshots-tab-volumes" panel-class="min-w-0">
    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-square-3-stack-3d"
            :title="__('Volume snapshots')"
            :note="__('Point-in-time snapshots of attached block-storage volumes, captured through your cloud provider.')"
            class="border-b border-brand-ink/10"
        />

        <div class="px-4 py-3.5 sm:px-5">
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-8 text-center">
                <span class="mx-auto flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/60 text-brand-moss ring-1 ring-brand-ink/10">
                    <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                </span>
                <p class="mt-2.5 text-sm font-semibold text-brand-ink">{{ __('Coming soon') }}</p>
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
