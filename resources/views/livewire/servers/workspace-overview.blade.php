@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="overview"
    :title="__('Server')"
    :description="__('Status, provider, SSH, and health for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($server->workspace)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
            <p class="font-semibold">{{ __('Project context') }}</p>
            <p class="mt-1 leading-relaxed text-brand-moss">
                {{ __('This server is managed as part of the :project project. Use the project pages when you need access control, grouped activity, shared variables, coordinated deploys, or cross-resource health review.', ['project' => $server->workspace->name]) }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <a href="{{ route('projects.overview', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project overview') }}</a>
                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project operations') }}</a>
            </div>
        </div>
    @endif

    <div class="{{ $card }} p-6 sm:p-8">
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Server details') }}</h2>
        <dl class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
            <div><dt class="text-sm text-brand-moss">{{ __('Status') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $server->status }}</dd></div>
            <div><dt class="text-sm text-brand-moss">{{ __('Provider') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $server->provider->label() }}</dd></div>
            @if ($server->setup_script_key)
                <div><dt class="text-sm text-brand-moss">{{ __('Setup script') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ config("setup_scripts.scripts.{$server->setup_script_key}.name", $server->setup_script_key) }}</dd></div>
                <div>
                    <dt class="text-sm text-brand-moss">{{ __('Setup status') }}</dt>
                    <dd class="mt-1 font-medium">
                        @if ($server->setup_status === 'done')
                            <span class="text-brand-forest">{{ __('Done') }}</span>
                        @elseif ($server->setup_status === 'failed')
                            <span class="text-red-700">{{ __('Failed') }}</span>
                        @elseif ($server->setup_status === 'running')
                            <span class="text-brand-copper">{{ __('Running') }}</span>
                        @else
                            <span class="text-brand-mist">{{ $server->setup_status ?? __('Pending') }}</span>
                        @endif
                    </dd>
                </div>
            @endif
            <div><dt class="text-sm text-brand-moss">{{ __('IP address') }}</dt><dd class="mt-1 font-mono font-medium text-brand-ink">{{ $server->ip_address ?? '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-sm text-brand-moss">{{ __('SSH') }}</dt><dd class="mt-1 break-all font-mono text-sm font-medium text-brand-ink">{{ $server->getSshConnectionString() }}</dd></div>
            @if ($server->status === 'ready')
                <div>
                    <dt class="text-sm text-brand-moss">{{ __('Health') }}</dt>
                    <dd class="mt-1 font-medium">
                        @if ($server->health_status === 'reachable')
                            <span class="text-brand-forest">{{ __('Reachable') }}</span>
                        @elseif ($server->health_status === 'unreachable')
                            <span class="text-red-700">{{ __('Unreachable') }}</span>
                        @else
                            <span class="text-brand-mist">—</span>
                        @endif
                        @if ($server->last_health_check_at)
                            <span class="text-sm font-normal text-brand-mist">({{ $server->last_health_check_at->diffForHumans() }})</span>
                        @endif
                    </dd>
                </div>
            @endif
        </dl>
        @if ($server->isReady() && $server->ip_address && $server->ssh_private_key)
            <div class="mt-8 border-t border-brand-ink/10 pt-8">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Server monitoring') }}</h3>
                <p class="mt-2 max-w-2xl text-sm text-brand-moss leading-relaxed">
                    {{ __('View CPU, memory, disk, and load history. Dply can install Python on the guest over SSH when needed, then collect metrics from the Monitor tab.') }}
                </p>
                @php
                    $monitorMeta = $server->meta ?? [];
                    $lastMetricAt = isset($monitorMeta['monitoring_last_sample_at'])
                        ? \Illuminate\Support\Carbon::parse($monitorMeta['monitoring_last_sample_at'])->timezone(config('app.timezone'))
                        : null;
                @endphp
                @if ($lastMetricAt)
                    <p class="mt-2 text-xs text-brand-mist">
                        {{ __('Last stored sample') }}: {{ $lastMetricAt->format('Y-m-d H:i') }}
                        <span class="text-brand-moss">({{ $lastMetricAt->diffForHumans() }})</span>
                    </p>
                @endif
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <a
                        href="{{ route('servers.monitor', $server) }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors"
                    >
                        <x-heroicon-o-chart-bar class="h-4 w-4 shrink-0 opacity-90" />
                        {{ __('Open Metrics') }}
                    </a>
                    <a href="{{ route('servers.services', $server) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-forest">{{ __('Or install packages under Services') }} →</a>
                </div>
            </div>
        @endif
        @if ($server->status === 'ready' && $server->ip_address)
            <div class="mt-8 space-y-4 border-t border-brand-ink/10 pt-8">
                <button type="button" wire:click="checkHealth" class="text-sm font-medium text-brand-sage hover:text-brand-forest">{{ __('Check health now') }}</button>
                <p class="text-sm text-brand-moss leading-relaxed">
                    {{ __('Add this server to a') }}
                    <a href="{{ route('status-pages.index') }}" class="font-medium text-brand-ink hover:underline">{{ __('status page') }}</a>
                    {{ __('to show reachability on your public status URL.') }}
                </p>
                <p class="text-sm text-brand-moss">{{ __('Optional HTTP health URL (2xx = reachable); otherwise SSH port is checked.') }}</p>
                <form wire:submit="saveHealthCheckUrl" class="flex max-w-xl flex-col gap-3 sm:flex-row sm:items-center">
                    <input type="url" wire:model="health_check_url" placeholder="https://…" class="flex-1 rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30" />
                    <x-primary-button type="submit" class="shrink-0 !py-2">{{ __('Save') }}</x-primary-button>
                </form>
            </div>
        @endif
    </div>
    @can('delete', $server)
        <div class="rounded-2xl border border-red-200/60 bg-white p-5 shadow-sm space-y-2">
            <p class="text-sm text-brand-moss leading-relaxed">{{ __('You must type the server name to confirm. You can remove the server now or pick a future date (removal runs at the end of that day in your app timezone).') }}</p>
            <button type="button" wire:click="openRemoveServerModal" class="text-sm font-medium text-red-700 hover:text-red-900">{{ __('Remove or schedule removal…') }}</button>
        </div>
    @endcan

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
