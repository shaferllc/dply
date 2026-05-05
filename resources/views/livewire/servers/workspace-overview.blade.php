@php
    $card = 'dply-card overflow-hidden';
    $setupIncomplete = $server->status !== \App\Models\Server::STATUS_READY || $server->setup_status !== \App\Models\Server::SETUP_STATUS_DONE;
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
    :description="__('At-a-glance health, sites, deploy status, and operations shortcuts for this server.')"
    :show-navigation="! $setupIncomplete"
    doc-route="docs.create-first-server"
    :doc-label="__('First server guide')"
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
            {{-- Setup-in-progress hero — unchanged from the previous design.
                 Renders only while server.setup_status hasn't reached DONE.
                 Its job is to push the operator to the journey page; nothing
                 else on this view matters until setup completes. --}}
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
                                        wire:loading.attr="disabled"
                                        wire:target="rerunSetup"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-sand/60 bg-brand-sand/20 px-4 py-3 text-sm font-semibold text-brand-ink transition hover:border-brand-sage hover:bg-brand-sand/35 hover:text-brand-sage sm:min-w-48"
                                    >
                                        <span wire:loading.remove wire:target="rerunSetup" class="inline-flex items-center gap-2">
                                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                                            {{ __('Re-run setup') }}
                                        </span>
                                        <span wire:loading wire:target="rerunSetup" class="inline-flex items-center gap-2">
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
        @else
            {{-- Ready-state hero — absorbed the unique bits from the
                 deprecated "Server snapshot" panel: size, setup-script
                 name, and full SSH connection string sit alongside the
                 status / provider / IP chips. --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-6 sm:p-7">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                    <div class="max-w-3xl space-y-4">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="inline-flex items-center gap-2 rounded-full border border-brand-ink/10 bg-white px-3 py-1.5 font-medium text-brand-ink">
                                <span class="inline-flex h-2 w-2 rounded-full {{ $healthSummary['status'] === \App\Models\Server::HEALTH_REACHABLE ? 'bg-emerald-500' : ($healthSummary['status'] === \App\Models\Server::HEALTH_UNREACHABLE ? 'bg-rose-500' : 'bg-brand-gold') }}"></span>
                                {{ $healthSummary['status'] === \App\Models\Server::HEALTH_REACHABLE ? __('Reachable') : ($healthSummary['status'] === \App\Models\Server::HEALTH_UNREACHABLE ? __('Needs attention') : __('No health check yet')) }}
                            </span>
                            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-3 py-1.5 text-brand-moss">
                                {{ __('Provider') }}: <span class="ml-2 font-semibold text-brand-ink">{{ $server->provider->label() }}</span>
                            </span>
                            @if ($server->region)
                                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-3 py-1.5 text-brand-moss">
                                    {{ __('Region') }}: <span class="ml-2 font-semibold text-brand-ink">{{ $server->region }}</span>
                                </span>
                            @endif
                            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-3 py-1.5 text-brand-moss">
                                {{ __('IP') }}: <span class="ml-2 font-mono font-semibold text-brand-ink">{{ $server->ip_address ?? '—' }}</span>
                            </span>
                            @if ($server->size)
                                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-3 py-1.5 text-brand-moss">
                                    {{ __('Size') }}: <span class="ml-2 font-mono font-semibold text-brand-ink">{{ $server->size }}</span>
                                </span>
                            @endif
                        </div>
                        <div class="space-y-2">
                            <h2 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ $server->name }}</h2>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-brand-moss">
                                <span class="inline-flex items-center gap-2 font-mono text-xs">
                                    <span class="text-brand-mist uppercase tracking-wider">SSH</span>
                                    <span class="break-all text-brand-ink">{{ $server->getSshConnectionString() }}</span>
                                </span>
                                @if ($server->setup_script_key)
                                    <span class="inline-flex items-center gap-2 text-xs">
                                        <span class="text-brand-mist uppercase tracking-wider">{{ __('Setup script') }}</span>
                                        <span class="text-brand-ink">{{ config("setup_scripts.scripts.{$server->setup_script_key}.name", $server->setup_script_key) }}</span>
                                    </span>
                                @endif
                            </div>
                            @if ($healthSummary['last_checked_at'])
                                <p class="text-xs text-brand-mist">
                                    {{ __('Last health check') }}: {{ $healthSummary['last_checked_at']->diffForHumans() }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            {{-- SSH-key reminder. Conditional, fires only when the
                 operator's personal profile key isn't yet on the server.
                 Carried over unchanged from the previous design. --}}
            @if (! $serverHasPersonalProfileKey)
                <section class="mt-6 rounded-2xl border border-brand-gold/40 bg-brand-sand/35 p-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-2xl">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Add your personal SSH key before you need this server') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-brand-moss">
                                @if ($hasProfileSshKeys)
                                    {{ __('This server is ready, but it does not yet include one of your personal profile SSH keys. Attach one from the SSH keys workspace and sync authorized_keys so your own login access is on the machine.') }}
                                @else
                                    {{ __('This server is ready, but you do not have any personal SSH keys saved in your profile yet. Add one first, then attach it from the SSH keys workspace so your own login access is on the machine.') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if (! $hasProfileSshKeys)
                                <a href="{{ route('profile.ssh-keys') }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-cream transition-colors hover:bg-brand-forest">
                                    {{ __('Add a profile key') }}
                                </a>
                            @endif
                            <a href="{{ route('servers.ssh-keys', $server) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink transition-colors hover:bg-brand-sand/30">
                                {{ __('Open SSH keys workspace') }}
                            </a>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Container launch progress. Conditional banner that only
                 renders for container-target launches that haven't
                 completed. Carried over unchanged. --}}
            @if ($containerLaunch)
                <section class="mt-6 overflow-hidden rounded-[2rem] border border-sky-200 bg-sky-50/90 p-6 shadow-sm">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-3xl space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-2 rounded-full border border-sky-300 bg-white px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700">
                                    <span class="h-2 w-2 rounded-full bg-sky-500"></span>
                                    {{ __('Container launch') }}
                                </span>
                                <span class="inline-flex items-center rounded-full border border-sky-200 bg-white px-3 py-1.5 text-xs font-medium text-sky-700">
                                    {{ str($containerLaunch['target_family'])->headline() }}
                                </span>
                            </div>
                            <h3 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ $containerLaunch['current_step_label'] }}</h3>
                            <p class="text-sm leading-6 text-brand-moss">{{ $containerLaunch['summary'] }}</p>
                            @if ($containerLaunch['site_route'])
                                <a href="{{ $containerLaunch['site_route'] }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white transition-colors hover:bg-sky-700">
                                    {{ __('Open container site') }}
                                </a>
                            @endif
                        </div>
                        @if ($containerLaunchTranscript !== '')
                            <details class="lg:max-w-md">
                                <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-sky-700">{{ __('Recent events') }}</summary>
                                <pre class="mt-3 max-h-64 overflow-auto whitespace-pre-wrap rounded-lg border border-sky-200 bg-white px-3 py-3 font-mono text-[11px] leading-5 text-brand-ink">{{ $containerLaunchTranscript }}</pre>
                            </details>
                        @endif
                    </div>
                </section>
            @endif

            {{-- 4 click-through stat tiles. Each one lands at its
                 dedicated workspace sub-page. Single source of truth
                 for the headline numbers; the dedicated pages own
                 detail. --}}
            <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @php
                    $healthValue = match ($healthSummary['status']) {
                        \App\Models\Server::HEALTH_REACHABLE => __('Reachable'),
                        \App\Models\Server::HEALTH_UNREACHABLE => __('Unreachable'),
                        default => __('Not checked yet'),
                    };
                    $healthMeta = $healthSummary['last_checked_at']
                        ? __('Last checked :time', ['time' => $healthSummary['last_checked_at']->diffForHumans()])
                        : __('No checks yet');
                    $deployingMeta = $deployingCount > 0
                        ? trans_choice('{1} :count site deploying|[2,*] :count sites deploying', $deployingCount, ['count' => $deployingCount])
                        : trans_choice('{0} No sites yet|{1} 1 site|[2,*] :count sites', $siteCount, ['count' => $siteCount]);
                    $latestDeployValue = $latestDeployment?->status
                        ? str($latestDeployment->status)->headline()
                        : __('None yet');
                    $latestDeployMeta = $latestDeployment?->site
                        ? __(':site · :time', [
                            'site' => $latestDeployment->site->name,
                            'time' => ($latestDeployment->finished_at ?? $latestDeployment->created_at)?->diffForHumans() ?? __('just now'),
                        ])
                        : __('No deploys yet');
                @endphp

                <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm transition hover:border-brand-sage hover:shadow-md">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Health') }}</p>
                    <p class="mt-2 text-xl font-semibold text-brand-ink">{{ $healthValue }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ $healthMeta }}</p>
                    <p class="mt-3 text-[11px] font-medium text-brand-sage opacity-0 transition group-hover:opacity-100">{{ __('Open Monitor →') }}</p>
                </a>

                <a href="{{ route('servers.sites', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm transition hover:border-brand-sage hover:shadow-md">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Sites') }}</p>
                    <p class="mt-2 text-xl font-semibold text-brand-ink">{{ $siteCount }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ $deployingMeta }}</p>
                    <p class="mt-3 text-[11px] font-medium text-brand-sage opacity-0 transition group-hover:opacity-100">{{ __('Open Sites →') }}</p>
                </a>

                <a href="{{ route('servers.databases', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm transition hover:border-brand-sage hover:shadow-md">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Databases') }}</p>
                    <p class="mt-2 text-xl font-semibold text-brand-ink">{{ $databaseSummary['count'] }}</p>
                    <p class="mt-1 text-xs text-brand-moss">
                        @if ($installedStack->database)
                            {{ str($installedStack->database)->headline() }}@if ($installedStack->databaseVersion) · {{ $installedStack->databaseVersion }}@endif
                        @else
                            {{ __('No engine recorded') }}
                        @endif
                    </p>
                    <p class="mt-3 text-[11px] font-medium text-brand-sage opacity-0 transition group-hover:opacity-100">{{ __('Open Databases →') }}</p>
                </a>

                <a href="{{ route('servers.deploys', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm transition hover:border-brand-sage hover:shadow-md">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Latest deploy') }}</p>
                    <p class="mt-2 text-xl font-semibold text-brand-ink">{{ $latestDeployValue }}</p>
                    <p class="mt-1 truncate text-xs text-brand-moss">{{ $latestDeployMeta }}</p>
                    <p class="mt-3 text-[11px] font-medium text-brand-sage opacity-0 transition group-hover:opacity-100">{{ __('Open Deploys →') }}</p>
                </a>
            </section>

            {{-- Stack summary card. One line of installed-runtime
                 facts (database engine + version, php, webserver, cache)
                 with a low-memory-mode badge when applicable. Reads via
                 InstalledStack::fromMeta so legacy servers degrade
                 gracefully to wizard meta. --}}
            <section class="mt-6 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Stack') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                            @if ($installedStack->database)
                                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/20 px-2.5 py-1 font-medium text-brand-ink">
                                    {{ str($installedStack->database)->headline() }}@if ($installedStack->databaseVersion)<span class="ml-1 font-mono text-xs text-brand-moss">{{ $installedStack->databaseVersion }}</span>@endif
                                </span>
                            @endif
                            @if ($installedStack->phpVersion)
                                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/20 px-2.5 py-1 font-medium text-brand-ink">
                                    PHP <span class="ml-1 font-mono text-xs text-brand-moss">{{ $installedStack->phpVersion }}</span>
                                </span>
                            @endif
                            @if ($installedStack->webserver)
                                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/20 px-2.5 py-1 font-medium text-brand-ink">
                                    {{ str($installedStack->webserver)->headline() }}
                                </span>
                            @endif
                            @if ($installedStack->cacheService && $installedStack->cacheService !== 'none')
                                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/20 px-2.5 py-1 font-medium text-brand-ink">
                                    {{ str($installedStack->cacheService)->headline() }}
                                </span>
                            @endif
                            @if ($installedStack->lowMemoryMode)
                                <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800" title="{{ __('Provisioned in low-memory mode — substituted lighter services where possible.') }}">
                                    <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Low-memory mode') }}
                                </span>
                            @endif
                        </div>
                        @if ($installedStackDiverges)
                            <p class="mt-2 text-xs text-amber-700">
                                {{ __('Wizard requested :requested but :installed was installed instead. See journey for context.', [
                                    'requested' => $server->meta['database'] ?? '—',
                                    'installed' => $installedStack->database ?? '—',
                                ]) }}
                            </p>
                        @endif
                    </div>
                    <a href="{{ route('servers.services', $server) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-forest">{{ __('Open Services →') }}</a>
                </div>
            </section>

            {{-- Conditional cards: Insights + Notifications. Each only
                 renders when there's something to surface (open insights,
                 attached channels). Empty-state operators see a cleaner
                 page; populated-state operators get the high-signal
                 summary at a glance. --}}
            @if ($openInsightsCount > 0)
                <section class="mt-4 rounded-2xl border {{ $criticalInsightsCount > 0 ? 'border-red-200 bg-red-50/40' : 'border-amber-200 bg-amber-50/40' }} p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] {{ $criticalInsightsCount > 0 ? 'text-red-700' : 'text-amber-700' }}">{{ __('Insights') }}</p>
                            <p class="mt-2 text-sm font-semibold text-brand-ink">
                                {{ trans_choice('{1} :count open finding|[2,*] :count open findings', $openInsightsCount, ['count' => $openInsightsCount]) }}
                                @if ($criticalInsightsCount > 0)
                                    <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">
                                        {{ trans_choice('{1} :count critical|[2,*] :count critical', $criticalInsightsCount, ['count' => $criticalInsightsCount]) }}
                                    </span>
                                @endif
                            </p>
                        </div>
                        <a href="{{ route('servers.insights', $server) }}" wire:navigate class="text-sm font-medium {{ $criticalInsightsCount > 0 ? 'text-red-700 hover:text-red-900' : 'text-amber-700 hover:text-amber-900' }}">
                            {{ __('Open Insights →') }}
                        </a>
                    </div>
                </section>
            @endif

            @if ($notificationSummary['manage_url'])
                <section class="mt-4 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Notifications') }}</p>
                            <p class="mt-2 text-sm text-brand-ink">
                                @if ($notificationSummary['channel_count'] > 0)
                                    {{ trans_choice('{1} :count channel routing this server|[2,*] :count channels routing this server', $notificationSummary['channel_count'], ['count' => $notificationSummary['channel_count']]) }}
                                @else
                                    {{ __('No channels routing yet — add one to get pinged when something matters.') }}
                                @endif
                            </p>
                        </div>
                        <a href="{{ $notificationSummary['manage_url'] }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-forest">
                            {{ __('Manage →') }}
                        </a>
                    </div>
                </section>
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
