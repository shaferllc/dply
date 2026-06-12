@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    ];

    // Container hosts (docker/kubernetes) don't run the VM-shaped setup journey,
    // so they're "setup complete" the moment they're STATUS_READY — even when
    // setup_status is null.
    $isContainerHost = in_array($server->hostKind(), [\App\Models\Server::HOST_KIND_DOCKER, \App\Models\Server::HOST_KIND_KUBERNETES], true);

    // Dedicated service boxes (redis/valkey/database) hide the generic
    // Sites/Databases/Latest-deploy/Background tiles and the Sites preview —
    // they render 0s on these servers and pull the operator toward set-up
    // flows that don't apply. Each role renders its own focused tile pack.
    $isCacheRoleHost = in_array((string) ($server->meta['server_role'] ?? ''), ['redis', 'valkey'], true);
    $isDatabaseRoleHost = (string) ($server->meta['server_role'] ?? '') === 'database';
    $isWorkerRoleHost = (string) ($server->meta['server_role'] ?? '') === 'worker';
    // Worker keeps site/deploy cards — it deploys sites through the same caddy
    // pipeline as application hosts and also runs queue workers from the code.
    $isDedicatedServiceRoleHost = $isCacheRoleHost || $isDatabaseRoleHost;
    $setupIncomplete = $server->isVmHost() && (
        $server->status !== \App\Models\Server::STATUS_READY
        || $server->setup_status !== \App\Models\Server::SETUP_STATUS_DONE
    );
    $containerLaunchTranscript = collect($containerLaunch['events'] ?? [])->map(function (array $event): string {
        $timestamp = (string) ($event['at'] ?? '');
        $level = strtoupper((string) ($event['level'] ?? 'info'));
        $message = (string) ($event['message'] ?? 'Container launch update');
        $lines = [];

        $prefixParts = array_values(array_filter([$timestamp, $level]));
        $lines[] = ($prefixParts !== [] ? '['.implode('] [', $prefixParts).'] ' : '').$message;

        foreach (collect($event['context'] ?? [])->filter(fn ($value) => ! is_array($value)) as $contextKey => $contextValue) {
            $rendered = is_bool($contextValue) ? ($contextValue ? 'true' : 'false') : (string) $contextValue;
            if ($rendered === '') {
                continue;
            }

            $lines[] = '  > '.str_replace('_', ' ', (string) $contextKey).': '.$rendered;
        }

        return implode("\n", $lines);
    })->implode("\n\n");
@endphp

<x-server-workspace-layout
    :server="$server"
    active="overview"
    :title="__('Overview')"
    :show-navigation="! $setupIncomplete"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @php
        $provisionError = is_array($server->meta['provision_error'] ?? null) ? $server->meta['provision_error'] : null;
    @endphp

    <div class="space-y-4">
        {{-- Provisioning + cluster error banners. --}}
        @include('livewire.servers.partials.overview._error-banners')

        {{-- Project context (feature-gated) --}}
        @include('livewire.servers.partials.overview._project-context')

        @if ($setupIncomplete)
            @include('livewire.servers.partials.overview._setup-hero')
        @else
            @include('livewire.servers.partials.overview._identity-hero')

            @include('livewire.servers.partials.overview._onboarding-checklist')

            @include('livewire.servers.partials.overview._ssh-key-reminder')

            {{-- Container launch progress partial. --}}
            @include('livewire.servers.partials._container-launch-progress')

            @include('livewire.servers.partials.overview._first-site-cta')

            {{-- Health label/meta — shared by the workspace summary tiles and the
                 dedicated database tile pack, so it's computed once at this scope. --}}
            @php
                $healthValue = match ($healthSummary['status']) {
                    \App\Models\Server::HEALTH_REACHABLE => __('Reachable'),
                    \App\Models\Server::HEALTH_UNREACHABLE => __('Unreachable'),
                    default => __('Not checked yet'),
                };
                $healthMeta = $healthSummary['last_checked_at']
                    ? __('Last checked :time', ['time' => $healthSummary['last_checked_at']->diffForHumans()])
                    : __('No checks yet');
            @endphp

            @include('livewire.servers.partials.overview._workspace-summary-tiles')

            @include('livewire.servers.partials.overview._live-metrics')

            @include('livewire.servers.partials.overview._cache-tile-pack')

            @include('livewire.servers.partials.overview._database-tile-pack')

            @include('livewire.servers.partials.overview._sites-preview')

            @include('livewire.servers.partials.overview._installed-runtime')

            @include('livewire.servers.partials.overview._secondary-shortcuts')
        @endif

        @include('livewire.servers.partials.overview._danger-zone')
    </div>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
