@php
    $statusTone = [
        \App\Modules\Queue\Models\QueueNamespace::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        \App\Modules\Queue\Models\QueueNamespace::STATUS_PAUSED => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10',
        \App\Modules\Queue\Models\QueueNamespace::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
    ];
    $money = fn (int $cents): string => '$'.number_format($cents / 100, 2);
    $peak = max(1, collect($throughput)->max('jobs') ?: 1);
@endphp

<div class="contents">
    <x-workspace-nav surface="local" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="$breadcrumbs" />

        <x-profile-shell
            dense
            :title="$namespace->name"
            :description="$endpoint !== '' ? $endpoint : __('No public endpoint is configured for dply Queue.')"
            icon="heroicon-o-queue-list"
        >
            <x-slot:actions>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusTone[$namespace->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                    {{ ucfirst($namespace->status) }}
                </span>
                @if ($canManage)
                    <button type="button" wire:click="startTierChange" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        {{ __('Change tier') }}
                    </button>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="{{ __('Queue at a glance') }}">
                    <x-fleet-stat :label="__('Pending')">
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">
                            {{ $depth === null ? __('—') : number_format($depth->pending) }}
                        </p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Claimable now') }}</p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('Reserved')">
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">
                            {{ $depth === null ? __('—') : number_format($depth->reserved) }}
                        </p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('In flight') }}</p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('Delayed')">
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">
                            {{ $depth === null ? __('—') : number_format($depth->delayed) }}
                        </p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Not yet due') }}</p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('Monthly')">
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">
                            @if (! $billable)
                                <span class="text-base font-semibold text-brand-forest">{{ __('Included') }}</span>
                            @elseif (! $billingEnabled)
                                <span class="text-base font-semibold text-brand-moss">{{ __('Free (beta)') }}</span>
                            @else
                                {{ $money($tier->priceCents) }}
                            @endif
                        </p>
                        <p class="mt-1 text-xs text-brand-mist">
                            {{ $tier->label }} — {{ number_format($tier->maxQueueDepth) }} {{ __('deep') }}
                        </p>
                    </x-fleet-stat>
                </dl>
            </x-slot:stats>

            <x-slot:tabs>
                <nav class="flex gap-1 px-3 sm:px-4" aria-label="{{ __('Queue sections') }}">
                    @foreach ([
                        'overview' => __('Overview'),
                        'credentials' => __('Credentials'),
                        'failed' => __('Failed jobs'),
                    ] as $key => $label)
                        <button
                            type="button"
                            wire:click="$set('tab', '{{ $key }}')"
                            @class([
                                'border-b-2 px-3 py-2 text-sm font-medium transition',
                                'border-brand-ink text-brand-ink' => $tab === $key,
                                'border-transparent text-brand-moss hover:text-brand-ink' => $tab !== $key,
                            ])
                        >
                            {{ $label }}
                            @if ($key === 'failed' && $failedJobs !== [])
                                <span class="ml-1 rounded-full bg-red-100 px-1.5 text-xs font-semibold text-red-700">{{ count($failedJobs) }}</span>
                            @endif
                        </button>
                    @endforeach
                </nav>
            </x-slot:tabs>

            <div class="px-3 py-4 sm:px-4">
                @if (! $billable)
                    <x-alert tone="success" class="mb-4">
                        {{ __('This queue serves a dply Serverless site, so it is included at no charge.') }}
                        @if ($namespace->site !== null)
                            {{ __('If “:site” moves off Serverless, the queue moves onto its :tier tier and we will tell you before it appears on a bill.', ['site' => $namespace->site->name, 'tier' => $tier->label]) }}
                        @endif
                    </x-alert>
                @endif

                {{-- ============ OVERVIEW ============ --}}
                @if ($tab === 'overview')
                    <div class="space-y-5">
                        <div>
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Jobs pushed') }}</h3>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                {{ __('Last 30 days. Shown for visibility only — dply Queue is priced by capacity tier, not per job.') }}
                            </p>

                            @if (collect($throughput)->sum('jobs') === 0)
                                <p class="mt-3 text-sm text-brand-mist">{{ __('Nothing pushed yet.') }}</p>
                            @else
                                <div class="mt-3 flex h-24 items-end gap-0.5" role="img" aria-label="{{ __('Jobs pushed per day over the last 30 days') }}">
                                    @foreach ($throughput as $point)
                                        <div
                                            class="flex-1 rounded-t bg-brand-sage/50 hover:bg-brand-sage"
                                            style="height: {{ max(2, (int) round($point['jobs'] / $peak * 100)) }}%"
                                            title="{{ $point['date'] }}: {{ number_format($point['jobs']) }} {{ __('jobs') }}"
                                        ></div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="border-t border-brand-ink/10 pt-4">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Connect your app') }}</h3>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                {{ __('Laravel speaks the SQS protocol out of the box, so there is no package to install. Add these to your .env:') }}
                            </p>
                            <x-cli-snippet class="mt-2">QUEUE_CONNECTION=dply
DPLY_QUEUE_URL={{ $endpoint }}
DPLY_QUEUE_KEY={{ $liveCredential?->accessKeyId() ?? 'your-access-key-id' }}
DPLY_QUEUE_SECRET={{ __('(shown once when minted)') }}</x-cli-snippet>

                            @if ($namespace->site_id === null)
                                {{-- An externally-hosted app has no dply-injected handler to
                                     register the connection for it, so it has to be said. --}}
                                <p class="mt-2 text-xs text-brand-moss">
                                    {{ __('Apps dply deploys get this wiring automatically. For an app hosted elsewhere, also add a `dply` connection to config/queue.php using the `sqs` driver with `\'endpoint\' => env(\'DPLY_QUEUE_URL\')` — the stock `sqs` block has no endpoint key and would route to real AWS.') }}
                                    <a href="{{ route('docs.markdown', 'queue') }}" class="font-medium text-brand-forest hover:underline">{{ __('Full setup guide') }}</a>
                                </p>
                            @endif
                        </div>

                        <div class="border-t border-brand-ink/10 pt-4">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Capacity') }}</h3>
                            <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                                <x-fact-row :label="__('Tier')">{{ $tier->label }}</x-fact-row>
                                <x-fact-row :label="__('Max depth')">{{ number_format($tier->maxQueueDepth) }} {{ __('jobs') }}</x-fact-row>
                                <x-fact-row :label="__('Rate limit')">{{ number_format($tier->requestsPerMinute) }} {{ __('req/min') }}</x-fact-row>
                                <x-fact-row :label="__('Attached site')">{{ $namespace->site?->name ?? __('None (external app)') }}</x-fact-row>
                            </dl>
                        </div>

                        @if ($canManage)
                            <div class="border-t border-brand-ink/10 pt-4">
                                <x-danger-button wire:click="confirmDelete">{{ __('Delete queue') }}</x-danger-button>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- ============ CREDENTIALS ============ --}}
                @if ($tab === 'credentials')
                    <div class="space-y-4">
                        @if ($freshSecret !== null)
                            <x-alert tone="success">
                                <p class="font-semibold">{{ __('New secret — copy it now.') }}</p>
                                <p class="mt-1 text-xs">{{ __('dply stores this encrypted because SigV4 must recompute the signature, but it is never displayed again.') }}</p>
                                <code class="mt-2 block break-all rounded bg-white/60 p-2 font-mono text-xs">{{ $freshSecret }}</code>
                            </x-alert>
                        @endif

                        <p class="text-xs text-brand-moss">
                            {{ __('Two credentials can be live at once, because a .env only reaches your app on its next deploy — mint the new one, deploy, then revoke the old.') }}
                        </p>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                <thead>
                                    <tr class="text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">
                                        <th scope="col" class="py-2 pr-3">{{ __('Access key') }}</th>
                                        <th scope="col" class="px-3 py-2">{{ __('Name') }}</th>
                                        <th scope="col" class="px-3 py-2">{{ __('Last used') }}</th>
                                        <th scope="col" class="px-3 py-2">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5">
                                    @foreach ($credentials as $credential)
                                        <tr>
                                            <td class="py-2.5 pr-3 font-mono text-xs text-brand-ink">{{ $credential->accessKeyId() }}</td>
                                            <td class="px-3 py-2.5 text-brand-moss">{{ $credential->name }}</td>
                                            <td class="px-3 py-2.5 text-brand-moss">
                                                {{ $credential->last_used_at?->diffForHumans() ?? __('Never') }}
                                            </td>
                                            <td class="px-3 py-2.5">
                                                @if ($credential->isRevoked())
                                                    <span class="text-xs text-brand-mist">{{ __('Revoked') }}</span>
                                                @elseif ($credential->isExpired())
                                                    <span class="text-xs text-amber-700">{{ __('Expired') }}</span>
                                                @else
                                                    <span class="text-xs font-medium text-brand-forest">{{ __('Live') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if ($canManageCredentials)
                            <button
                                type="button"
                                wire:click="rotateCredential"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest"
                            >
                                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Mint new credential') }}
                            </button>
                        @endif
                    </div>
                @endif

                {{-- ============ FAILED JOBS ============ --}}
                @if ($tab === 'failed')
                    <div class="space-y-4">
                        @if (! $failedJobsAvailable)
                            <x-alert tone="warning">
                                {{ __('The job store could not be reached, so failed jobs cannot be listed right now.') }}
                            </x-alert>
                        @elseif ($failedJobs === [])
                            <x-empty-state
                                icon="heroicon-o-exclamation-triangle"
                                :title="__('No failed jobs recorded')"
                                :description="$ownsFailedJobs
                                    ? __('Nothing has failed on this queue.')
                                    : __('Laravel writes failed jobs to your own application database by default, so this list stays empty until your app is pointed at dply\'s failed-job store. This is not the same as having had no failures.')"
                            />
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                    <thead>
                                        <tr class="text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">
                                            <th scope="col" class="py-2 pr-3">{{ __('Job') }}</th>
                                            <th scope="col" class="px-3 py-2">{{ __('Queue') }}</th>
                                            <th scope="col" class="px-3 py-2">{{ __('Failed') }}</th>
                                            <th scope="col" class="px-3 py-2">{{ __('Error') }}</th>
                                            <th scope="col" class="py-2 pl-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5">
                                        @foreach ($failedJobs as $job)
                                            <tr class="align-top">
                                                <td class="py-2.5 pr-3">
                                                    <button type="button" wire:click="inspectJob('{{ $job['id'] }}')" class="text-left font-medium text-brand-ink hover:text-brand-forest">
                                                        {{ $job['name'] }}
                                                    </button>
                                                    @if ($job['retried_at'] !== null)
                                                        <span class="ml-1 rounded-full bg-brand-sage/15 px-1.5 text-xs font-medium text-brand-forest">{{ __('Retried') }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2.5 text-brand-moss">{{ $job['queue'] }}</td>
                                                <td class="px-3 py-2.5 text-brand-moss">{{ $job['failed_at']?->diffForHumans() ?? __('—') }}</td>
                                                <td class="max-w-md truncate px-3 py-2.5 font-mono text-xs text-brand-moss" title="{{ $job['exception_summary'] }}">
                                                    {{ $job['exception_summary'] }}
                                                </td>
                                                <td class="py-2.5 pl-3 text-right whitespace-nowrap">
                                                    @if ($canManage && $job['retried_at'] === null)
                                                        <button type="button" wire:click="retryJob('{{ $job['id'] }}')" class="text-xs font-semibold text-brand-forest hover:underline">
                                                            {{ __('Retry') }}
                                                        </button>
                                                    @endif
                                                    @if ($canManage)
                                                        <button type="button" wire:click="forgetJob('{{ $job['id'] }}')" class="ml-2 text-xs font-semibold text-brand-mist hover:text-red-700">
                                                            {{ __('Delete') }}
                                                        </button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </x-profile-shell>
    </div>

    {{-- Tier-change modal --}}
    <x-modal name="queue-tier-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        @php
            $selected = $tiers[$selectedTier] ?? $tier;
            $isUpgrade = $billable && $selected->priceCents > $tier->priceCents;
        @endphp
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-semibold text-brand-ink">{{ __('Change capacity tier') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('A tier sets how deep this queue may get and how fast your app may call it.') }}
            </p>

            <div class="mt-4 space-y-2">
                @foreach ($tiers as $slug => $option)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-brand-ink/10 p-3 hover:bg-brand-sand/20">
                        <input type="radio" wire:model.live="selectedTier" value="{{ $slug }}" class="mt-1 border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="text-sm font-semibold text-brand-ink">{{ $option->label }}</span>
                                <span class="text-sm tabular-nums text-brand-ink">{{ $money($option->priceCents) }}/{{ __('mo') }}</span>
                            </span>
                            <span class="mt-0.5 block text-xs text-brand-moss">
                                {{ number_format($option->maxQueueDepth) }} {{ __('jobs deep') }} · {{ number_format($option->requestsPerMinute) }} {{ __('req/min') }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>

            @if ($isUpgrade)
                <label class="mt-3 flex items-start gap-2 rounded-lg bg-brand-sand/30 p-3">
                    <input type="checkbox" wire:model="confirmTierCharge" class="mt-0.5 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                    <span class="text-xs text-brand-moss">
                        {{ __('I understand this raises the monthly charge to :price.', ['price' => $money($selected->priceCents)]) }}
                    </span>
                </label>
            @endif

            @if ($selected->maxQueueDepth < $tier->maxQueueDepth)
                <x-alert tone="warning" class="mt-3">
                    {{ __('This is a smaller tier. Pushes are rejected once the queue is deeper than :depth jobs — if it is currently deeper than that, drain it before switching.', ['depth' => number_format($selected->maxQueueDepth)]) }}
                </x-alert>
            @endif

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancelTierChange" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                    {{ __('Cancel') }}
                </button>
                <button type="button" wire:click="changeTier" class="rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                    {{ __('Change tier') }}
                </button>
            </div>
        </div>
    </x-modal>

    {{-- Failed-job detail modal --}}
    <x-modal name="queue-failed-job-modal" :show="false" maxWidth="2xl" overlayClass="bg-brand-ink/30" focusable>
        <div class="p-5 sm:p-6">
            @if ($inspectingJob !== null)
                <h2 class="text-base font-semibold text-brand-ink">{{ $inspectingJob['name'] }}</h2>
                <p class="mt-0.5 text-xs text-brand-moss">
                    {{ $inspectingJob['queue'] }} · {{ __('attempt :n', ['n' => $inspectingJob['attempts']]) }} ·
                    {{ $inspectingJob['failed_at']?->diffForHumans() ?? __('unknown time') }}
                </p>

                <pre class="mt-3 max-h-80 overflow-auto rounded-lg bg-brand-ink/5 p-3 font-mono text-xs text-brand-ink">{{ $inspectingJob['exception'] }}</pre>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeJob" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                        {{ __('Close') }}
                    </button>
                    @if ($canManage && $inspectingJob['retried_at'] === null)
                        <button type="button" wire:click="retryJob('{{ $inspectingJob['id'] }}')" class="rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                            {{ __('Retry job') }}
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </x-modal>

    {{-- Delete modal --}}
    <x-modal name="queue-delete-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-semibold text-brand-ink">{{ __('Delete :name?', ['name' => $namespace->name]) }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Any jobs still in this queue are discarded, and apps using its credentials will start failing to enqueue. This cannot be undone.') }}
            </p>

            @if ($depth !== null && $depth->total() > 0)
                <x-alert tone="warning" class="mt-3">
                    {{ trans_choice(
                        ':count job is still queued and will be discarded.|:count jobs are still queued and will be discarded.',
                        $depth->total(),
                        ['count' => number_format($depth->total())],
                    ) }}
                </x-alert>
            @endif

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancelDelete" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                    {{ __('Cancel') }}
                </button>
                <x-danger-button wire:click="deleteNamespace">{{ __('Delete queue') }}</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
