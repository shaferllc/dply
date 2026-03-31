@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $setupIncomplete = $server->status !== \App\Models\Server::STATUS_READY || $server->setup_status !== \App\Models\Server::SETUP_STATUS_DONE;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="overview"
    :title="__('Server')"
    :description="__('Status, provider, SSH, and health for this server.')"
    :show-navigation="! $setupIncomplete"
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
        @if ($setupIncomplete)
            <section class="relative overflow-hidden rounded-[2rem] border border-brand-ink/10 bg-brand-ink px-6 py-7 text-brand-cream shadow-[0_30px_90px_rgba(19,28,23,0.18)] sm:px-8 sm:py-8">
                <div class="pointer-events-none absolute inset-0">
                    <div class="absolute inset-x-0 top-0 h-px bg-white/10"></div>
                    <div class="absolute -right-16 top-1/2 h-40 w-40 -translate-y-1/2 rounded-full bg-brand-sage/20 blur-3xl"></div>
                </div>

                <div class="relative max-w-4xl">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.24em] text-brand-sand/90">
                            <span class="inline-flex h-2 w-2 rounded-full bg-amber-300 shadow-[0_0_0_4px_rgba(252,211,77,0.16)]"></span>
                            {{ __('Setup in progress') }}
                        </span>
                        <span class="inline-flex items-center rounded-full border border-white/10 bg-black/10 px-3 py-1.5 text-xs font-medium text-brand-cream/80">
                            {{ __('Workspace unlocks after setup finishes') }}
                        </span>
                    </div>

                    <div class="mt-6">
                        <h2 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl sm:leading-tight">
                            {{ __('Finish setup before using this server.') }}
                        </h2>
                        <p class="mt-3 max-w-3xl text-base leading-7 text-brand-cream/78">
                            {{ __('Reconnect over SSH, watch live installation output, and re-run setup safely if this server needs another pass before the workspace is unlocked.') }}
                        </p>

                        <div class="mt-6 flex flex-wrap gap-3 text-sm text-brand-cream/75">
                            <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-2">
                                {{ __('Provider') }}: <span class="ml-2 font-semibold text-white">{{ $server->provider->label() }}</span>
                            </span>
                            <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-2">
                                {{ __('IP') }}: <span class="ml-2 font-mono font-semibold text-white">{{ $server->ip_address ?? '—' }}</span>
                            </span>
                            <span class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-2">
                                {{ __('Setup') }}: <span class="ml-2 font-semibold text-white">{{ ucfirst($server->setup_status ?? __('Pending')) }}</span>
                            </span>
                        </div>

                        <div class="mt-12 max-w-3xl rounded-[1.5rem] border border-white/10 bg-white/95 p-5 text-brand-ink shadow-[0_20px_70px_rgba(12,18,15,0.16)]">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Next step') }}</p>
                            <p class="mt-2 text-lg font-semibold tracking-tight text-brand-ink">{{ __('Open the setup journey') }}</p>
                            <p class="mt-2 text-sm leading-6 text-brand-moss">
                                {{ __('Watch live progress, inspect current output, and re-run installation from a clean tracked setup task if needed.') }}
                            </p>
                            <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                                <a
                                    href="{{ route('servers.journey', $server) }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-3 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest sm:min-w-56"
                                >
                                    <x-heroicon-o-wrench-screwdriver class="h-4 w-4" />
                                    {{ __('Open setup journey') }}
                                </a>
                                @if (\App\Jobs\RunSetupScriptJob::shouldDispatch($server))
                                    <button
                                        type="button"
                                        wire:click="rerunSetup"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-sand/60 bg-brand-sand/20 px-4 py-3 text-sm font-semibold text-brand-ink transition hover:border-brand-sage hover:bg-brand-sand/35 hover:text-brand-sage sm:min-w-48"
                                    >
                                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                                        {{ __('Re-run setup') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @else
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
            @if (\App\Jobs\RunSetupScriptJob::shouldDispatch($server))
                <div class="mt-8 border-t border-brand-ink/10 pt-8">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Provisioning') }}</h3>
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Open the setup journey to watch the tracked install flow, review captured output, and run another pass after changing local provisioning options like force reinstall.') }}
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <a
                            href="{{ route('servers.journey', $server) }}"
                            wire:navigate
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0 opacity-90" />
                            {{ __('Open setup journey') }}
                        </a>
                    </div>
                </div>
            @endif
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
        @endif
    </div>
    @if (! $setupIncomplete)
    @can('delete', $server)
        <div class="rounded-2xl border border-red-200/60 bg-white p-5 shadow-sm space-y-2">
            <p class="text-sm text-brand-moss leading-relaxed">{{ __('You must type the server name to confirm. You can remove the server now or pick a future date (removal runs at the end of that day in your app timezone).') }}</p>
            <button type="button" wire:click="openRemoveServerModal" class="text-sm font-medium text-red-700 hover:text-red-900">{{ __('Remove or schedule removal…') }}</button>
        </div>
    @endcan
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
