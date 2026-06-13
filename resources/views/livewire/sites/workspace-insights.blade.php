<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Insights'),
        'currentIcon' => 'light-bulb',
    ])

    <x-hero-card
        :eyebrow="__('Site')"
        :title="__('Insights')"
        :description="__('Monitoring and recommendations for this site.')"
        icon="light-bulb"
    >
        <x-slot:topAction>
            <x-primary-button size="sm" type="button" wire:click="runChecksNow" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="runChecksNow">{{ __('Refresh') }}</span>
                <span wire:loading wire:target="runChecksNow">{{ __('Queueing…') }}</span>
            </x-primary-button>
        </x-slot:topAction>
    </x-hero-card>

    @if (session('success'))
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">{{ session('success') }}</div>
    @endif

    {{-- Workspace console banner. Two banner sources participate at the site level —
         `run` (site insight sweep) and `fix` (apply-fix on a site-scoped finding). The
         underlying jobs route their state writes to site.meta when the operation is
         site-scoped, keeping the server insights banner free of site-specific noise. --}}
    @php
        $insightsBanner = null;
        foreach (['run', 'fix'] as $kind) {
            $status = (string) data_get($site->meta ?? [], config("insights_workspace.meta_{$kind}_status_key"));
            $runId = (string) data_get($site->meta ?? [], config("insights_workspace.meta_{$kind}_run_id_key"));
            if ($runId === '' || ! in_array($status, ['queued', 'running', 'completed', 'failed', 'refused'], true)) {
                continue;
            }
            $busy = in_array($status, ['queued', 'running'], true);
            $startedAt = (string) data_get($site->meta ?? [], config("insights_workspace.meta_{$kind}_started_at_key"));
            $rank = $busy ? '9999-12-31T23:59:59Z' : $startedAt;
            if ($insightsBanner === null
                || ($busy && ! $insightsBanner['busy'])
                || ($busy === $insightsBanner['busy'] && $rank > $insightsBanner['rank'])
            ) {
                $insightsBanner = [
                    'kind' => $kind,
                    'status' => $status,
                    'busy' => $busy,
                    'rank' => $rank,
                    'started_at' => $startedAt,
                    'finished_at' => (string) data_get($site->meta ?? [], config("insights_workspace.meta_{$kind}_finished_at_key")),
                    'error' => (string) data_get($site->meta ?? [], config("insights_workspace.meta_{$kind}_error_key")),
                ];
            }
        }

        if ($insightsBanner !== null) {
            $bk = $insightsBanner['kind'];
            $bs = $insightsBanner['status'];
            $bbusy = $insightsBanner['busy'];

            $insightsBanner['output'] = match ($bk) {
                'run' => $this->runOutputLines,
                'fix' => $this->fixOutputLines,
            };

            $insightsBanner['message'] = match ([$bk, $bs]) {
                ['run', 'queued'] => __('Insights run queued — waiting for a worker to pick it up…'),
                ['run', 'running'] => __('Running insight checks on site :site …', ['site' => $site->name]),
                ['run', 'completed'] => __('Insight checks complete.'),
                ['run', 'failed'] => __('Insight checks failed.'),
                ['fix', 'queued'] => __('Apply fix queued — waiting for a worker to pick it up…'),
                ['fix', 'running'] => __('Applying fix on site :site …', ['site' => $site->name]),
                ['fix', 'completed'] => __('Fix applied.'),
                ['fix', 'failed'] => __('Fix failed.'),
                ['fix', 'refused'] => __('Fix refused.'),
                default => '',
            };

            $insightsBanner['subtitle'] = $bbusy
                ? __('Refreshing every 4s · safe to leave this page — the job runs on the queue.')
                : match (true) {
                    in_array($bs, ['failed', 'refused'], true) && $insightsBanner['error'] !== ''
                        => $insightsBanner['error'],
                    $bs === 'completed' && $insightsBanner['finished_at'] !== ''
                        => __('Finished :time', ['time' => \Illuminate\Support\Carbon::parse($insightsBanner['finished_at'])->diffForHumans()]),
                    default => null,
                };

            $insightsBanner['banner_status'] = $bs === 'refused' ? 'failed' : $bs;
        }
    @endphp

    @if ($insightsBanner !== null)
        <x-workspace-console-banner
            :status="$insightsBanner['banner_status']"
            :message="$insightsBanner['message']"
            :subtitle="$insightsBanner['subtitle']"
            :output="$insightsBanner['output']"
            :busy="$insightsBanner['busy']"
            :dismiss-action="$insightsBanner['busy'] ? null : 'dismissInsightsBanner(\'' . $insightsBanner['kind'] . '\')'"
            :poll-action="$insightsBanner['busy'] ? 'pollInsightsStatus' : null"
            poll-interval="4s"
            :default-expanded="true"
        />
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <x-server-workspace-tablist ariaLabel="{{ __('Insights sections') }}">
            <x-server-workspace-tab wire:click="setTab('overview')" :active="$tab === 'overview'">{{ __('Overview') }}</x-server-workspace-tab>
            <x-server-workspace-tab wire:click="setTab('notifications')" :active="$tab === 'notifications'">{{ __('Notifications') }}</x-server-workspace-tab>
            <x-server-workspace-tab wire:click="setTab('settings')" :active="$tab === 'settings'">{{ __('Settings') }}</x-server-workspace-tab>
        </x-server-workspace-tablist>
    </div>

    @if ($tab === 'overview')
        <div class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-clipboard-document-check class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Insights') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Findings for this site') }}</h2>
                </div>
            </div>
            @if ($findings->isEmpty())
                <p class="px-5 py-10 text-sm text-brand-moss text-center">{{ __('No findings yet.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($findings as $f)
                        @php
                            $fix = config('insights.insights.'.$f->insight_key.'.fix');
                            $canFix = is_array($fix) && ($fix['handler'] ?? null);
                        @endphp
                        <li class="px-5 py-4 flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-semibold uppercase tracking-wide rounded-md px-2 py-0.5
                                        @class([
                                            'bg-amber-50 text-amber-950' => $f->severity === 'warning',
                                            'bg-red-50 text-red-900' => $f->severity === 'critical',
                                            'bg-brand-sand/80 text-brand-ink' => $f->severity === 'info',
                                        ])">{{ $f->severity }}</span>
                                    <span class="font-medium text-brand-ink">{{ $f->title }}</span>
                                </div>
                                @if ($f->body)
                                    <p class="mt-2 text-sm text-brand-moss whitespace-pre-wrap">{{ $f->body }}</p>
                                @endif
                                @include('livewire.partials.insight-correlation', ['finding' => $f])
                            </div>
                            @if ($canFix)
                                <x-secondary-button size="sm" type="button" wire:click="openConfirmActionModal('applyFix', [{{ $f->id }}], @js(__('Apply suggested fix')), @js(__('Apply the suggested fix on the server?')), @js(__('Apply fix')), true)" class="shrink-0">
                                    {{ __('Apply fix') }}
                                </x-secondary-button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($tab === 'notifications')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm p-6 space-y-4 text-sm text-brand-moss max-w-2xl">
            <p>{{ __('Deploy completions, deployment start, and uptime transitions for this site are configured under Site workspace → Notifications. Connect outbound webhooks and channel subscriptions there.') }}</p>
            <p>{{ __('Insights findings still use the server’s “Insights alerts” subscription when enabled.') }}</p>
            <div class="flex flex-wrap gap-2 pt-1">
                <x-primary-button size="sm" href="{{ route('sites.show', [$server, $site, 'section' => 'notifications']) }}" wire:navigate>{{ __('Open site Notifications') }}</x-primary-button>
                <x-secondary-button size="sm" href="{{ route('profile.notification-channels') }}" wire:navigate>{{ __('Manage notification channels') }}</x-secondary-button>
            </div>
        </div>
    @endif

    @if ($tab === 'settings')
        @include('livewire.partials.insights-settings-form', ['catalog' => $insightsCatalog, 'orgHasPro' => $orgHasPro])
        <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-brand-ink/10">
            <div class="flex flex-wrap gap-2">
                <x-secondary-button size="sm" type="button" wire:click="enableAll">{{ __('Enable all') }}</x-secondary-button>
                <x-secondary-button size="sm" type="button" wire:click="disableAll">{{ __('Disable all') }}</x-secondary-button>
            </div>
            <x-primary-button size="sm" type="button" wire:click="saveSettings">{{ __('Save settings') }}</x-primary-button>
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
