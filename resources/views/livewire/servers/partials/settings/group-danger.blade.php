@php
    $summary = $this->serverRemovalSummary;
    $scheduledAt = $server->scheduled_deletion_at;
    $scheduledReason = data_get($server->meta, 'scheduled_deletion_reason');
    $blocked = $summary['running_deployments'] > 0;
    $protected = $server->isDeletionProtected();

    // What a removal actually takes with it, in the order an operator worries
    // about it. Zero-count rows still render, greyed — "0 databases" is itself
    // reassurance, and a missing row reads as an oversight.
    $inventory = [
        ['label' => trans_choice('Site|Sites', $summary['sites']), 'count' => $summary['sites'], 'icon' => 'heroicon-o-globe-alt'],
        ['label' => trans_choice('Database|Databases', $summary['databases']), 'count' => $summary['databases'], 'icon' => 'heroicon-o-circle-stack'],
        ['label' => trans_choice('Cron job|Cron jobs', $summary['cron_jobs']), 'count' => $summary['cron_jobs'], 'icon' => 'heroicon-o-clock'],
        ['label' => trans_choice('Daemon|Daemons', $summary['supervisor_programs']), 'count' => $summary['supervisor_programs'], 'icon' => 'heroicon-o-cpu-chip'],
        ['label' => trans_choice('Firewall rule|Firewall rules', $summary['firewall_rules']), 'count' => $summary['firewall_rules'], 'icon' => 'heroicon-o-shield-check'],
        ['label' => trans_choice('SSH key|SSH keys', $summary['authorized_keys']), 'count' => $summary['authorized_keys'], 'icon' => 'heroicon-o-key'],
        ['label' => trans_choice('Recipe|Recipes', $summary['recipes']), 'count' => $summary['recipes'], 'icon' => 'heroicon-o-command-line'],
    ];

    $strip = 'border-b border-brand-ink/10 px-5 py-4 sm:px-6';
@endphp

<div id="settings-danger" class="{{ $card }} scroll-mt-24">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-exclamation-triangle"
        :title="__('Danger zone')"
        :note="__('Removing this server is permanent. Read what it takes with it before you start.')"
        tone="danger"
        class="border-b border-brand-ink/10"
    />

    {{-- Live state first: a removal already on the clock outranks everything
         else on this tab, and cancelling it must be one click from here. --}}
    @if ($scheduledAt)
        <div class="{{ $strip }} bg-rose-50/70">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 flex-1 items-start gap-3">
                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-500/10 text-rose-600 ring-1 ring-rose-500/20" aria-hidden="true">
                        <x-heroicon-o-clock class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-rose-800">
                            {{ __('This server is scheduled for removal') }}
                        </p>
                        <p class="mt-1 text-sm leading-relaxed text-rose-700">
                            {{ __('It will be removed :relative — :absolute. Nothing has been deleted yet.', [
                                'relative' => $scheduledAt->diffForHumans(),
                                'absolute' => $scheduledAt->toDayDateTimeString(),
                            ]) }}
                        </p>
                        @if ($scheduledReason)
                            <p class="mt-1.5 text-sm text-rose-700/90">
                                <span class="font-medium">{{ __('Reason:') }}</span> {{ $scheduledReason }}
                            </p>
                        @endif
                    </div>
                </div>

                @can('delete', $server)
                    <x-secondary-button
                        type="button"
                        size="xs"
                        class="shrink-0"
                        wire:click="cancelScheduledServerRemoval"
                        wire:loading.attr="disabled"
                        wire:target="cancelScheduledServerRemoval"
                    >
                        <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Cancel scheduled removal') }}
                    </x-secondary-button>
                @endcan
            </div>
        </div>
    @endif

    {{-- Blocker, surfaced here rather than as a validation error after the
         operator has already typed the server name into the modal. --}}
    @if ($blocked)
        <div class="{{ $strip }} bg-amber-50/70">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-700 ring-1 ring-amber-500/20" aria-hidden="true">
                    <x-heroicon-o-arrow-path class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-amber-900">
                        {{ trans_choice(':count deployment is running|:count deployments are running', $summary['running_deployments'], ['count' => $summary['running_deployments']]) }}
                    </p>
                    <p class="mt-1 text-sm leading-relaxed text-amber-800">
                        {{ __('Immediate removal is blocked until they finish or are cancelled. You can still schedule a removal for later.') }}
                        <a href="{{ route('servers.sites', ['server' => $server]) }}" wire:navigate class="font-medium underline underline-offset-2">{{ __('View sites') }}</a>
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- The two questions people actually have: what happens in dply, and what
         happens to the machine I'm paying for. --}}
    <div class="{{ $strip }}">
        <h3 class="text-sm font-semibold text-brand-ink">{{ __('What removal does') }}</h3>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3">
                <p class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                    <x-heroicon-o-server-stack class="h-4 w-4 text-brand-moss" aria-hidden="true" />
                    {{ __('In dply') }}
                </p>
                <p class="mt-1.5 text-sm leading-relaxed text-brand-moss">
                    {{ __('The server and everything recorded against it is deleted from :org — sites, database records, cron jobs, daemons, firewall rules, authorized keys, recipes, notes and deploy history. This cannot be undone, and re-adding the server later starts from an empty record.', [
                        'org' => $summary['organization_name'] ?? __('your organization'),
                    ]) }}
                </p>
            </div>

            <div @class([
                'rounded-xl border px-4 py-3',
                'border-rose-200 bg-rose-50/60' => $summary['will_destroy_cloud'],
                'border-brand-ink/10 bg-brand-sand/10' => ! $summary['will_destroy_cloud'],
            ])>
                <p @class([
                    'flex items-center gap-2 text-sm font-semibold',
                    'text-rose-800' => $summary['will_destroy_cloud'],
                    'text-brand-ink' => ! $summary['will_destroy_cloud'],
                ])>
                    <x-heroicon-o-cloud class="h-4 w-4" aria-hidden="true" />
                    {{ __('At :provider', ['provider' => $summary['provider_label']]) }}
                </p>

                @if ($summary['will_destroy_cloud'])
                    <p class="mt-1.5 text-sm leading-relaxed text-rose-700">
                        {{ __('The instance is destroyed at the provider, along with its disk. Anything on that machine that is not already in an off-server backup is gone.') }}
                    </p>
                    @if ($server->provider_id)
                        <p class="mt-1.5 font-mono text-xs text-rose-700/80">{{ __('Instance') }} {{ $server->provider_id }}</p>
                    @endif
                @else
                    <p class="mt-1.5 text-sm leading-relaxed text-brand-moss">
                        {{ __('Nothing is destroyed at the provider — dply only forgets this server. The machine keeps running, keeps serving whatever is installed on it, and you keep being billed for it. Tear it down yourself if that is not what you want.') }}
                    </p>
                @endif
            </div>
        </div>

        <p class="mt-3 text-xs leading-relaxed text-brand-moss">
            {{ __('Removal does not uninstall anything over SSH. Sites, services and data on the machine are only affected when the provider instance itself is destroyed.') }}
        </p>
    </div>

    {{-- Blast radius, counted. --}}
    <div class="{{ $strip }}">
        <h3 class="text-sm font-semibold text-brand-ink">{{ __('What this server currently holds') }}</h3>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Everything below is deleted from dply along with the server.') }}</p>

        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
            @foreach ($inventory as $item)
                <div @class([
                    'rounded-xl border px-3 py-2.5 text-center',
                    'border-brand-ink/10 bg-white' => $item['count'] > 0,
                    'border-brand-ink/5 bg-brand-sand/10' => $item['count'] === 0,
                ])>
                    <x-dynamic-component
                        :component="$item['icon']"
                        @class([
                            'mx-auto h-4 w-4',
                            'text-brand-moss' => $item['count'] > 0,
                            'text-brand-moss/40' => $item['count'] === 0,
                        ])
                        aria-hidden="true"
                    />
                    <p @class([
                        'mt-1 text-lg font-semibold tabular-nums',
                        'text-brand-ink' => $item['count'] > 0,
                        'text-brand-moss/40' => $item['count'] === 0,
                    ])>{{ $item['count'] }}</p>
                    <p class="text-xs leading-tight text-brand-moss">{{ $item['label'] }}</p>
                </div>
            @endforeach
        </div>

        @if ($summary['sites'] > 0)
            @php $siteNames = $server->sites()->orderBy('name')->limit(6)->pluck('name'); @endphp
            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                <span class="text-xs font-medium text-brand-moss">{{ __('Sites that go with it:') }}</span>
                @foreach ($siteNames as $siteName)
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs text-brand-ink ring-1 ring-brand-ink/10">{{ $siteName }}</span>
                @endforeach
                @if ($summary['sites'] > $siteNames->count())
                    <span class="text-xs text-brand-moss">{{ __('and :count more', ['count' => $summary['sites'] - $siteNames->count()]) }}</span>
                @endif
            </div>
        @endif
    </div>

    {{-- How the removal itself is paced, so the modal's three modes aren't a
         surprise the first time someone opens it. --}}
    @can('delete', $server)
        <div class="{{ $strip }}">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('How it happens') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('You pick the timing in the next step. Every option requires typing the server name exactly, and organization owners and admins are emailed when a removal is scheduled or carried out.') }}
            </p>

            <dl class="mt-3 space-y-2">
                <div class="flex items-start gap-3 rounded-lg border border-brand-ink/10 bg-white px-3 py-2.5">
                    <dt class="w-32 shrink-0 text-sm font-semibold text-brand-ink">{{ __('Remove now') }}</dt>
                    <dd class="min-w-0 flex-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Runs immediately with no undo. Blocked while a deployment is running.') }}
                    </dd>
                </div>
                <div class="flex items-start gap-3 rounded-lg border border-brand-ink/10 bg-white px-3 py-2.5">
                    <dt class="w-32 shrink-0 text-sm font-semibold text-brand-ink">{{ __('In 30 minutes') }}</dt>
                    <dd class="min-w-0 flex-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('A grace window. Nothing is touched until the timer runs out, and you can cancel from this tab at any point.') }}
                    </dd>
                </div>
                <div class="flex items-start gap-3 rounded-lg border border-brand-ink/10 bg-white px-3 py-2.5">
                    <dt class="w-32 shrink-0 text-sm font-semibold text-brand-ink">{{ __('On a date') }}</dt>
                    <dd class="min-w-0 flex-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Removed at the end of the day you choose. Useful for decommissioning with a deadline — cancellable right up to it.') }}
                    </dd>
                </div>
            </dl>
        </div>
    @endcan

    {{-- The action, or why there isn't one. --}}
    <div class="px-5 py-4 sm:px-6">
        @can('delete', $server)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-rose-800">{{ __('Remove this server') }}</h3>
                    <p class="mt-0.5 text-sm text-brand-moss">
                        {{ __('Opens the confirmation step — nothing is removed until you finish it.') }}
                    </p>
                </div>
                <x-danger-button type="button" size="xs" class="shrink-0" wire:click="openRemoveServerModal">
                    <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ $scheduledAt ? __('Change removal…') : __('Remove or schedule removal…') }}
                </x-danger-button>
            </div>
        @elseif ($protected)
            <div class="flex items-start gap-2.5 rounded-lg border border-brand-ink/10 bg-brand-sand/40 px-4 py-3 text-sm text-brand-moss">
                <x-heroicon-o-lock-closed class="mt-0.5 h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                <p>{{ __('This is dply infrastructure and is protected from deletion. It cannot be removed from the host or the database.') }}</p>
            </div>
        @else
            <div class="flex items-start gap-2.5 rounded-lg border border-brand-ink/10 bg-brand-sand/40 px-4 py-3 text-sm text-brand-moss">
                <x-heroicon-o-lock-closed class="mt-0.5 h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                <p>{{ __('Your role cannot remove servers. Ask an organization owner or admin if this server should go.') }}</p>
            </div>
        @endcan
    </div>
</div>
