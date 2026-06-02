@php
    use App\Models\Server;
    $isVmHost = ! in_array($server->hostKind(), [Server::HOST_KIND_DOCKER, Server::HOST_KIND_KUBERNETES], true)
        && ! $site->usesFunctionsRuntime()
        && ! $site->usesEdgeRuntime();
@endphp

<div class="space-y-6">
    <section id="connection" class="scroll-mt-24">
        <livewire:sites.repository
            :server="$server"
            :site="$site"
            :embedded="true"
            lockedTab="connection"
            wire:key="deployments-settings-connection-{{ $site->id }}"
        />
    </section>

    @if ($site->server?->isDigitalOceanFunctionsHost())
        <section id="hooks" class="scroll-mt-24">
            <livewire:sites.deploy-hooks
                :site="$site"
                wire:key="deployments-settings-hooks-{{ $site->id }}"
            />
        </section>
    @endif
</div>

@if ($section !== '')
    <script>
        document.addEventListener('livewire:navigated', () => {
            const el = document.getElementById(@js($section));
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, { once: true });
        queueMicrotask(() => {
            const el = document.getElementById(@js($section));
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    </script>
@endif
