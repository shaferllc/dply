@php
    use App\Models\Server;
    $isVmHost = ! in_array($server->hostKind(), [Server::HOST_KIND_DOCKER, Server::HOST_KIND_KUBERNETES], true)
        && ! $site->usesFunctionsRuntime()
        && ! $site->usesEdgeRuntime();
@endphp

<div class="space-y-6">
    {{-- Connection / Branches removed from Settings — they live under
         Repository → Connection / Repository → Branches now. Settings only
         holds deploy-time controls (Quick deploy webhook + Functions hooks). --}}

    <section id="webhook" class="scroll-mt-24">
        <livewire:sites.repository
            :server="$server"
            :site="$site"
            :embedded="true"
            lockedTab="webhook"
            wire:key="deployments-settings-webhook-{{ $site->id }}"
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

@if ($settingsSection !== '')
    <script>
        document.addEventListener('livewire:navigated', () => {
            const el = document.getElementById(@js($settingsSection));
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, { once: true });
        queueMicrotask(() => {
            const el = document.getElementById(@js($settingsSection));
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    </script>
@endif
