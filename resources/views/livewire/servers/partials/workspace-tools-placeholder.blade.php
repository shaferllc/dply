{{--
    Lazy-load skeleton for Tools. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and tool row stubs).

    Role-aware on purpose: a service-only box (database / cache / load balancer)
    has no tool catalog and no Runtimes tab, so painting five tool rows and a
    two-tab strip here meant the page visibly rearranged itself the moment the
    real render landed. A skeleton that predicts the wrong shape is worse than no
    skeleton — it reads as the page loading twice.
--}}
@php
    $skeletonRole = (string) ($server->meta['server_role'] ?? '');
    $skeletonServiceHost = in_array($skeletonRole, ['redis', 'valkey', 'database', 'load_balancer'], true);
@endphp
<x-server-workspace-layout
    :server="$server"
    active="tools"
    :title="__('Tools')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading tools…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-wrench-screwdriver"
            :title="__('Tools')"
            :note="$skeletonServiceHost
                ? __('Host-level actions for this server. This server type has no installable CLIs.')
                : __('Installed CLIs and version managers from the inventory probe — install, upgrade, or repair from here.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-5 py-3.5 sm:px-6" aria-hidden="true">
            <div class="h-3.5 w-40 animate-pulse rounded bg-brand-ink/10"></div>
            <span class="h-8 w-28 animate-pulse rounded-lg bg-brand-ink/10"></span>
        </div>

        @unless ($skeletonServiceHost)
            <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
                @foreach ([__('Tools'), __('Runtimes')] as $i => $label)
                    <span @class([
                        'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                        'bg-brand-ink text-white' => $i === 0,
                        'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                    ])>{{ $label }}</span>
                @endforeach
            </div>

            <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
                @foreach (range(1, 5) as $row)
                    <li class="flex items-center gap-3 px-5 py-3.5 sm:px-6">
                        <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-36 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                        <span class="h-8 w-20 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    </li>
                @endforeach
            </ul>
        @else
            {{-- What actually lands on a service box: the Host power card — one
                 compact row, action inline on the right. Keep this shape in step
                 with the real card in workspace-tools.blade.php. --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-3 px-5 py-4 sm:px-6" aria-hidden="true">
                <span class="h-8 w-8 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-3 w-28 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-full max-w-xl animate-pulse rounded bg-brand-ink/10"></div>
                </div>
                <span class="h-7 w-32 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
            </div>
        @endunless
    </section>
</x-server-workspace-layout>
