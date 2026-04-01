@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $setupIncomplete = $server->status !== \App\Models\Server::STATUS_READY || $server->setup_status !== \App\Models\Server::SETUP_STATUS_DONE;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="overview"
    :title="__('Overview')"
    :description="__('At-a-glance health, sites, deploy status, and operations shortcuts for this server.')"
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
                            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-3 py-1.5 text-brand-moss">
                                {{ __('IP') }}: <span class="ml-2 font-mono font-semibold text-brand-ink">{{ $server->ip_address ?? '—' }}</span>
                            </span>
                        </div>
                        <div class="space-y-2">
                            <h2 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Overview') }}</h2>
                            <p class="max-w-2xl text-sm leading-6 text-brand-moss">
                                {{ __('A lighter, development-facing summary for this server while the overview flag is enabled.') }}
                            </p>
                            @if ($healthSummary['last_checked_at'])
                                <p class="text-sm text-brand-mist">
                                    {{ __('Last health check') }}: {{ $healthSummary['last_checked_at']->diffForHumans() }}
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3 lg:max-w-sm lg:justify-end">
                        <a href="{{ route('servers.sites', $server) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-sm font-medium text-brand-ink transition hover:bg-brand-sand/20">
                            <x-heroicon-o-globe-alt class="h-4 w-4" />
                            {{ __('Open Sites') }}
                        </a>
                        <a href="{{ route('servers.deploy', $server) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-sm font-medium text-brand-ink transition hover:bg-brand-sand/20">
                            <x-heroicon-o-rocket-launch class="h-4 w-4" />
                            {{ __('Open Deploy') }}
                        </a>
                        <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-sm font-medium text-brand-ink transition hover:bg-brand-sand/20">
                            <x-heroicon-o-chart-bar class="h-4 w-4" />
                            {{ __('Open Metrics') }}
                        </a>
                    </div>
                </div>
            </section>

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
                        <div class="flex flex-wrap gap-3">
                            <a href="{{ route('servers.ssh-keys', $server) }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                                <x-heroicon-o-key class="h-4 w-4" />
                                {{ __('Open SSH keys') }}
                            </a>
                            @if (! $hasProfileSshKeys)
                                <a href="{{ route('profile.ssh-keys') }}" wire:navigate class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                                    {{ __('Add profile key') }}
                                </a>
                            @endif
                        </div>
                    </div>
                </section>
            @endif

            <section class="mt-6 grid gap-5 lg:grid-cols-2 xl:grid-cols-4">
                <x-stat-card
                    :label="__('Health')"
                    :value="$healthSummary['status'] === \App\Models\Server::HEALTH_REACHABLE ? __('Reachable') : ($healthSummary['status'] === \App\Models\Server::HEALTH_UNREACHABLE ? __('Unreachable') : __('Not checked yet'))"
                    :meta="$healthSummary['last_checked_at'] ? __('Last checked :time', ['time' => $healthSummary['last_checked_at']->diffForHumans()]) : __('No health checks recorded yet.')"
                    tone="subtle"
                />
                <x-stat-card
                    :label="__('Sites')"
                    :value="$siteCount"
                    :meta="trans_choice('{0} No hosted sites yet.|{1} 1 site connected to this server.|[2,*] :count sites connected to this server.', $siteCount, ['count' => $siteCount])"
                />
                <x-stat-card
                    :label="__('Latest deploy')"
                    :value="$latestDeployment?->status ? str($latestDeployment->status)->headline() : __('None yet')"
                    :meta="$latestDeployment?->site ? __('Latest run for :site', ['site' => $latestDeployment->site->name]).($latestDeployment->finished_at ? ' '.__('(:time)', ['time' => $latestDeployment->finished_at->diffForHumans()]) : '') : __('No deploys have been recorded for this server yet.')"
                />
                <x-stat-card
                    :label="__('Operations')"
                    :value="array_sum($opsSummary)"
                    :meta="__('Configured items across firewall, cron, daemons, and SSH keys.')"
                />
            </section>

            <section class="mt-8 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div class="max-w-2xl">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Insights') }}</h3>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Open server findings are summarized here so you can spot issues without leaving overview.') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-badge tone="accent">
                            {{ trans_choice('{0} No open findings|{1} :count open finding|[2,*] :count open findings', $insightSummary['open_count'], ['count' => $insightSummary['open_count']]) }}
                        </x-badge>
                        @if ($insightSummary['critical_count'] > 0)
                            <x-badge tone="danger">
                                {{ trans_choice('{1} :count critical|[2,*] :count critical', $insightSummary['critical_count'], ['count' => $insightSummary['critical_count']]) }}
                            </x-badge>
                        @endif
                        @if ($insightSummary['warning_count'] > 0)
                            <x-badge tone="warning">
                                {{ trans_choice('{1} :count warning|[2,*] :count warnings', $insightSummary['warning_count'], ['count' => $insightSummary['warning_count']]) }}
                            </x-badge>
                        @endif
                        <a href="{{ route('servers.insights', $server) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-forest">{{ __('Open Insights') }}</a>
                    </div>
                </div>

                @if ($insightFindings->isEmpty())
                    <x-empty-state
                        :title="__('No open server insights right now.')"
                        :description="__('If you expected to see something here, open Insights to refresh checks, review enabled settings, and confirm this server has recent metrics when metric-based checks are enabled.')"
                        class="mt-5"
                    />
                @else
                    <div class="mt-5 grid gap-3 lg:grid-cols-3">
                        @foreach ($insightFindings as $finding)
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide rounded-md px-2 py-0.5
                                        @class([
                                            'bg-amber-50 text-amber-950' => $finding->severity === 'warning',
                                            'bg-red-50 text-red-900' => $finding->severity === 'critical',
                                            'bg-brand-sand/80 text-brand-ink' => $finding->severity === 'info',
                                        ])">{{ $finding->severity }}</span>
                                    <p class="text-sm font-semibold text-brand-ink">{{ $finding->title }}</p>
                                </div>
                                @if ($finding->body)
                                    <p class="mt-2 text-sm leading-6 text-brand-moss">{{ $finding->body }}</p>
                                @endif
                                <p class="mt-3 text-xs text-brand-mist">
                                    {{ __('Detected') }}:
                                    {{ $finding->detected_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') ?? '—' }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="mt-8 grid gap-10 xl:grid-cols-[1.1fr,0.9fr]">
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Sites') }}</h3>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Review the sites on this server and jump into each app or the full sites workspace.') }}</p>
                        </div>
                        <a href="{{ route('servers.sites', $server) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-forest">{{ __('Open Sites') }}</a>
                    </div>

                    <div class="mt-5 space-y-3">
                        @forelse ($siteSummaries as $siteSummary)
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <a href="{{ $siteSummary['route'] }}" wire:navigate class="font-semibold text-brand-ink hover:text-brand-sage">{{ $siteSummary['name'] }}</a>
                                        <p class="mt-1 text-sm text-brand-moss">{{ $siteSummary['primary_domain'] ?? __('No primary domain yet') }}</p>
                                    </div>
                                    <span class="rounded-full border border-brand-ink/10 bg-white px-3 py-1 text-xs font-medium text-brand-moss">{{ str($siteSummary['status'])->headline() }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-4 py-6 text-sm text-brand-moss">
                                {{ __('No sites are attached to this server yet.') }}
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="space-y-10">
                    <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Notifications') }}</h3>
                                <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Add channels, assign common server events here, and open the deeper notification pages only when you need more control.') }}</p>
                            </div>
                            @if ($server->organization_id)
                                <a
                                    href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}"
                                    wire:navigate
                                    class="text-sm font-medium text-brand-sage hover:text-brand-forest"
                                >
                                    {{ __('Assign events') }}
                                </a>
                            @endif
                        </div>

                        <div class="mt-5 space-y-4">
                            <x-resource-notification-summary
                                :resource="$server"
                                :heading="__('Server notifications')"
                                :manage-url="route('profile.notification-channels.bulk-assign', ['server' => $server->id])"
                            />

                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-semibold text-brand-ink">{{ __('Quick assign') }}</h4>
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('Pick one or more channels plus the server events you want routed from this server.') }}</p>
                                    </div>
                                    <a
                                        href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}"
                                        wire:navigate
                                        class="text-xs font-medium text-brand-sage hover:text-brand-forest"
                                    >
                                        {{ __('Open advanced assignment') }}
                                    </a>
                                </div>

                                <div class="mt-4 rounded-2xl border border-brand-ink/10 bg-white p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h5 class="text-sm font-semibold text-brand-ink">{{ __('Quick add channel') }}</h5>
                                            <p class="mt-1 text-sm text-brand-moss">{{ __('Create a new destination here, then it will be selected automatically for assignment below.') }}</p>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="openQuickNotificationChannelModal"
                                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-plus class="h-4 w-4 shrink-0 opacity-90" />
                                            {{ __('Add channel') }}
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-5 lg:grid-cols-2">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Channels') }}</p>
                                        <div class="mt-3 space-y-2">
                                            @forelse ($assignableChannels as $channel)
                                                <label class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-3 py-2 text-sm text-brand-ink">
                                                    <input
                                                        type="checkbox"
                                                        wire:model.live="quick_notification_channel_ids"
                                                        value="{{ $channel->id }}"
                                                        class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                                                    >
                                                    <span>
                                                        <span class="font-medium">{{ $channel->label }}</span>
                                                        <span class="text-brand-mist">[{{ \App\Models\NotificationChannel::labelForType($channel->type) }}]</span>
                                                    </span>
                                                </label>
                                            @empty
                                                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-white px-3 py-3 text-sm text-brand-moss">
                                                    {{ __('No channels available yet. Create one from My channels or Organization channels first.') }}
                                                </div>
                                            @endforelse
                                        </div>
                                        @error('quick_notification_channel_ids')
                                            <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Server events') }}</p>
                                        <div class="mt-3 space-y-2">
                                            @foreach ($serverEventOptions as $eventKey => $eventLabel)
                                                <label class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-3 py-2 text-sm text-brand-ink">
                                                    <input
                                                        type="checkbox"
                                                        wire:model.live="quick_notification_event_keys"
                                                        value="{{ $eventKey }}"
                                                        class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                                                    >
                                                    <span>{{ $eventLabel }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        @error('quick_notification_event_keys')
                                            <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        wire:click="saveQuickNotificationAssignments"
                                        wire:loading.attr="disabled"
                                        wire:target="saveQuickNotificationAssignments"
                                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <x-heroicon-o-bell-alert class="h-4 w-4 shrink-0 opacity-90" />
                                        <span wire:loading.remove wire:target="saveQuickNotificationAssignments">{{ __('Save quick assignment') }}</span>
                                        <span wire:loading wire:target="saveQuickNotificationAssignments">{{ __('Saving…') }}</span>
                                    </button>
                                    <a
                                        href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}"
                                        wire:navigate
                                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-adjustments-horizontal class="h-4 w-4 shrink-0 opacity-90" />
                                        {{ __('Advanced assignment') }}
                                    </a>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <a
                                    href="{{ route('profile.notification-channels') }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-bell class="h-4 w-4 shrink-0 opacity-90" />
                                    {{ __('My channels') }}
                                </a>
                                @if ($server->organization_id)
                                    <a
                                        href="{{ route('organizations.notification-channels', $server->organization_id) }}"
                                        wire:navigate
                                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-building-office-2 class="h-4 w-4 shrink-0 opacity-90" />
                                        {{ __('Organization channels') }}
                                    </a>
                                    <a
                                        href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}"
                                        wire:navigate
                                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest"
                                    >
                                        <x-heroicon-o-adjustments-horizontal class="h-4 w-4 shrink-0 opacity-90" />
                                        {{ __('Assign server events') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Latest deploy') }}</h3>
                                <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Use this summary to spot the most recent release outcome before opening the full deploy workspace.') }}</p>
                            </div>
                            <a href="{{ route('servers.deploy', $server) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-forest">{{ __('Open Deploy') }}</a>
                        </div>

                        @if ($latestDeployment?->site)
                            <div class="mt-5 rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-4">
                                <p class="text-sm font-semibold text-brand-ink">{{ $latestDeployment->site->name }}</p>
                                <p class="mt-1 text-sm text-brand-moss">{{ str($latestDeployment->status)->headline() }}</p>
                                @if ($latestDeployment->finished_at)
                                    <p class="mt-2 text-xs text-brand-mist">{{ __('Finished :time', ['time' => $latestDeployment->finished_at->diffForHumans()]) }}</p>
                                @endif
                            </div>
                        @else
                            <div class="mt-5 rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/10 p-4 text-sm text-brand-moss">
                                {{ __('No deploys have been recorded for this server yet.') }}
                            </div>
                        @endif
                    </div>

                    <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Operations') }}</h3>
                                <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Keep this compact. Use the dedicated workspace pages when you need to manage firewall, jobs, daemons, or SSH access in detail.') }}</p>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            <a href="{{ route('servers.firewall', $server) }}" wire:navigate class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-4 transition hover:border-brand-sage/40 hover:bg-brand-sand/20">
                                <p class="text-sm font-semibold text-brand-ink">{{ trans_choice('{1} :count enabled firewall rule|[2,*] :count enabled firewall rules', $opsSummary['firewall_rules_enabled'], ['count' => $opsSummary['firewall_rules_enabled']]) }}</p>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Firewall') }}</p>
                            </a>
                            <a href="{{ route('servers.cron', $server) }}" wire:navigate class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-4 transition hover:border-brand-sage/40 hover:bg-brand-sand/20">
                                <p class="text-sm font-semibold text-brand-ink">{{ trans_choice('{1} :count cron job|[2,*] :count cron jobs', $opsSummary['cron_jobs'], ['count' => $opsSummary['cron_jobs']]) }}</p>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Cron') }}</p>
                            </a>
                            <a href="{{ route('servers.daemons', $server) }}" wire:navigate class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-4 transition hover:border-brand-sage/40 hover:bg-brand-sand/20">
                                <p class="text-sm font-semibold text-brand-ink">{{ trans_choice('{1} :count daemon|[2,*] :count daemons', $opsSummary['daemons'], ['count' => $opsSummary['daemons']]) }}</p>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Daemons') }}</p>
                            </a>
                            <a href="{{ route('servers.ssh-keys', $server) }}" wire:navigate class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-4 transition hover:border-brand-sage/40 hover:bg-brand-sand/20">
                                <p class="text-sm font-semibold text-brand-ink">{{ trans_choice('{1} :count SSH key|[2,*] :count SSH keys', $opsSummary['ssh_keys'], ['count' => $opsSummary['ssh_keys']]) }}</p>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('SSH keys') }}</p>
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-8 grid gap-10 xl:grid-cols-[1fr,1fr]">
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-brand-ink">{{ __('Server snapshot') }}</h3>
                    <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><dt class="text-sm text-brand-moss">{{ __('Status') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ str($server->status)->headline() }}</dd></div>
                        <div><dt class="text-sm text-brand-moss">{{ __('Provider') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $server->provider->label() }}</dd></div>
                        <div><dt class="text-sm text-brand-moss">{{ __('Region') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $server->region ?: '—' }}</dd></div>
                        <div><dt class="text-sm text-brand-moss">{{ __('Size') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $server->size ?: '—' }}</dd></div>
                        @if ($server->setup_script_key)
                            <div><dt class="text-sm text-brand-moss">{{ __('Setup script') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ config("setup_scripts.scripts.{$server->setup_script_key}.name", $server->setup_script_key) }}</dd></div>
                        @endif
                        <div><dt class="text-sm text-brand-moss">{{ __('Setup status') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ str($server->setup_status ?: 'pending')->headline() }}</dd></div>
                        <div><dt class="text-sm text-brand-moss">{{ __('IP address') }}</dt><dd class="mt-1 font-mono font-medium text-brand-ink">{{ $server->ip_address ?? '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-sm text-brand-moss">{{ __('SSH') }}</dt><dd class="mt-1 break-all font-mono text-sm font-medium text-brand-ink">{{ $server->getSshConnectionString() }}</dd></div>
                    </dl>

                    @if (\App\Jobs\RunSetupScriptJob::shouldDispatch($server))
                        <div class="mt-6 border-t border-brand-ink/10 pt-6">
                            <h4 class="text-sm font-semibold text-brand-ink">{{ __('Provisioning') }}</h4>
                            <p class="mt-2 text-sm leading-6 text-brand-moss">{{ __('Open the setup journey to review the tracked install flow, inspect output, or run setup again after changing provisioning inputs.') }}</p>
                            <div class="mt-4">
                                <a href="{{ route('servers.journey', $server) }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm transition-colors hover:bg-brand-forest">
                                    <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0 opacity-90" />
                                    {{ __('Open setup journey') }}
                                </a>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-brand-ink">{{ __('Health monitoring') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-brand-moss">{{ __('Add an HTTP URL when you want Dply to check your app itself. If you leave it blank, Dply only checks whether the server is reachable over SSH.') }}</p>
                    @if ($healthSummary['monitor_last_sample_at'])
                        <p class="mt-3 text-xs text-brand-mist">
                            {{ __('Last stored metrics sample') }}: {{ $healthSummary['monitor_last_sample_at']->format('Y-m-d H:i') }}
                            <span class="text-brand-moss">({{ $healthSummary['monitor_last_sample_at']->diffForHumans() }})</span>
                        </p>
                    @endif
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="checkHealth" wire:loading.attr="disabled" wire:target="checkHealth" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-2.5 text-sm font-medium text-brand-ink transition hover:bg-brand-sand/35">
                            <span wire:loading.remove wire:target="checkHealth" class="inline-flex items-center gap-2">
                                <x-heroicon-o-bolt class="h-4 w-4" />
                                {{ __('Check health now') }}
                            </span>
                            <span wire:loading wire:target="checkHealth" class="inline-flex items-center gap-2">
                                <x-spinner variant="ink" size="sm" />
                                {{ __('Checking…') }}
                            </span>
                        </button>
                        <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-forest">{{ __('Open Metrics') }}</a>
                    </div>
                    <div class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-4">
                        <p class="text-sm leading-6 text-brand-moss">
                            {{ __('This overview is internal. If you want a public heartbeat later, publish that through a') }}
                            <a href="{{ route('status-pages.index') }}" class="font-medium text-brand-ink hover:underline">{{ __('status page') }}</a>
                            {{ __('instead of exposing this workspace page.') }}
                        </p>
                    </div>
                    <p class="mt-4 text-sm text-brand-moss">{{ __('Optional HTTP health URL (2xx = healthy). Leave it blank to use SSH reachability only.') }}</p>
                    <form wire:submit="saveHealthCheckUrl" class="mt-4 flex max-w-xl flex-col gap-3 sm:flex-row sm:items-center">
                        <input type="url" wire:model="health_check_url" placeholder="https://…" class="flex-1 rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30" />
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveHealthCheckUrl" class="shrink-0 !py-2">
                            <span wire:loading.remove wire:target="saveHealthCheckUrl">{{ __('Save') }}</span>
                            <span wire:loading wire:target="saveHealthCheckUrl" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Saving…') }}
                            </span>
                        </x-primary-button>
                    </form>
                </div>
            </section>
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

    <x-notification-channel-quick-add-modal
        :show="$showQuickNotificationChannelModal"
        :types="$quickAddTypes"
        :current-type="$quick_new_type"
        :can-manage-organization-notification-channels="$canManageOrganizationNotificationChannels"
        :title="__('Quick add channel')"
        :description="__('Create a new destination here, then it will be selected automatically for assignment below.')"
    />

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
