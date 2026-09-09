{{--
    Lazy-load skeleton for Sites. Mirrors the merged page (hide-hero + single
    card with the dense identity head and site directory rows), so the geometry
    matches what replaces it.
--}}
@php
    $isContainerHost = in_array($server->hostKind(), [\App\Models\Server::HOST_KIND_DOCKER, \App\Models\Server::HOST_KIND_KUBERNETES], true);

    // Derived, not copied. The literal that used to sit here was the `default`
    // arm of the real view's match — and Server::siteType() only ever returns
    // container|php|static|node, so that arm is unreachable and the skeleton was
    // showing the wrong note on every single load.
    $skeletonSiteType = $server->siteType();
    $isWorkerHost = $server->isWorkerHost();
    $skeletonSitesNote = $isWorkerHost
        ? __('Queue workers from deployed code — same repo and queues, no public site.')
        : match ($skeletonSiteType) {
            'container' => __('Point dply at a Git repo. We inspect the Dockerfile or Kubernetes manifest and deploy onto this host.'),
            'php' => __('Deploy PHP/Laravel apps from Git — config, SSL, and deploys in each site workspace.'),
            'static' => __('Host static sites from Git with zero-config builds.'),
            'node' => __('Deploy Node.js apps from Git, with build and NPM support.'),
            default => __('Manage sites on this server — deploys, env, and settings per workspace.'),
        };
    // The blocked callout is a tall band on the real page. Reserve its height
    // here when the gate is already closed, or the panel visibly jumps down as
    // the hydrate response lands. Same assess() the component uses.
    $skeletonBlocked = ! \App\Support\Sites\SiteCreateAccess::canCreate($server);
    $skeletonSitesIcon = $isWorkerHost
        ? 'heroicon-o-square-3-stack-3d'
        : match ($skeletonSiteType) {
            'container' => 'heroicon-o-cube-transparent',
            'php' => 'heroicon-o-code-bracket',
            'static' => 'heroicon-o-photo',
            'node' => 'heroicon-o-bolt',
            default => 'heroicon-o-globe-alt',
        };
@endphp

<x-server-workspace-layout
    :server="$server"
    active="sites"
    :title="$isWorkerHost ? __('Workload') : __('Sites')"
    hide-hero
>
    <div class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading sites…') }}</span>

        {{-- Dense head, matching the merged page. --}}
        <x-workspace-panel-head
            dense
            :icon="$skeletonSitesIcon"
            :title="$isWorkerHost ? __('Workload') : ($isContainerHost ? __('Container apps') : __('Sites'))"
            :note="$skeletonSitesNote"
            class="border-b border-brand-ink/10"
        />

        @if ($skeletonBlocked)
            <div class="border-b border-amber-300 bg-amber-50 px-4 py-3.5 sm:px-5" aria-hidden="true">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 h-8 w-8 shrink-0 animate-pulse rounded-xl bg-amber-200/70"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-64 max-w-full animate-pulse rounded bg-amber-200/70"></div>
                        <div class="h-2.5 w-80 max-w-full animate-pulse rounded bg-amber-200/60"></div>
                        <div class="h-6 w-44 animate-pulse rounded-lg bg-amber-200/60"></div>
                    </div>
                </div>
            </div>
        @endif

        <div class="border-b border-brand-ink/10 px-4 py-2 sm:px-5" aria-hidden="true">
            <div class="flex items-center gap-2">
                <x-heroicon-o-rectangle-stack class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">
                    {{ $isWorkerHost ? __('Queue workload') : ($isContainerHost ? __('Container apps') : __('Site directory')) }}
                </p>
            </div>
            <div class="mt-2 h-3 w-48 animate-pulse rounded bg-brand-ink/10"></div>
        </div>

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 3) as $row)
                <li class="flex items-center gap-3 px-4 py-3 sm:px-5">
                    <span class="inline-flex h-9 w-9 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                    <span class="h-6 w-14 animate-pulse rounded-md bg-brand-ink/10"></span>
                    <span class="h-6 w-16 animate-pulse rounded-md bg-brand-ink/15"></span>
                </li>
            @endforeach
        </ul>
    </div>
</x-server-workspace-layout>
