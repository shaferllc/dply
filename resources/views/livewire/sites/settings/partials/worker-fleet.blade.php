{{-- Worker servers (the app's worker pool) — detect attached worker SERVERS and
     scale them up/down. Distinct from the Workers/daemons tab (Supervisor
     processes on this box). Nested inside the Settings merged card. --}}
@php
    $pools = $site->attachedWorkerPools();
    $explicitPoolIds = $site->workerPools()->pluck('worker_pools.id')->all();
    $workerProcs = function ($m) {
        $s = is_array(data_get($m->meta, 'pool.stats')) ? data_get($m->meta, 'pool.stats') : [];

        return max((int) ($s['horizon_procs'] ?? 0), (int) ($s['queue_procs'] ?? 0), (int) ($s['sv_running'] ?? 0));
    };
    $rowBtn = 'inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50';
@endphp

<div class="min-w-0">
    @if ($this->fleetIsInstalling())
        <div wire:poll.4s class="hidden" aria-hidden="true"></div>
    @endif

    @if ($this->fleetScaleRun() && ! $showWorkerProcessModal)
        <div class="border-b border-brand-ink/10">
            @include('livewire.partials.console-action-banner-static', [
                'run' => $this->fleetScaleRun(),
                'kindLabels' => (array) config('console_actions.kinds', []),
                'embedded' => true,
            ])
        </div>
    @endif

    @if ($pools->isEmpty())
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-3 sm:px-6">
            <p class="min-w-0 flex-1 text-sm text-brand-moss">
                <span class="font-medium text-brand-ink">{{ __('No worker servers yet.') }}</span>
                {{ __('Add a worker VM of this site — same repo and queue, no webserver. Scheduler and migrations stay here.') }}
            </p>
            @can('update', $site)
                <button type="button" wire:click="openAddWorkerModal" wire:loading.attr="disabled" wire:target="openAddWorkerModal" class="{{ $rowBtn }} shrink-0">
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Add worker') }}
                </button>
            @endcan
        </div>
    @else
        @foreach ($pools as $pool)
            @php
                $members = $pool->servers;
                $active = $members->count();
                $desired = (int) $pool->desired_count;
                $cap = (int) ($pool->max_size ?: 50);
                $primary = $pool->primaryServer;
                $hz = is_array($pool->meta['horizon'] ?? null) ? $pool->meta['horizon'] : [];
                $poolProcs = (int) $members->sum(fn ($m) => $workerProcs($m));
                $collectedAt = $members->map(fn ($m) => data_get($m->meta, 'pool.stats.collected_at'))->filter()->sort()->last();
                $crossServer = $pool->source_server_id !== null && $pool->source_server_id !== $site->server_id;
            @endphp
            <section class="border-b border-brand-ink/10" x-data="{ n: {{ $desired ?: $active }} }">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-2.5 sm:px-6">
                    <x-heroicon-o-square-3-stack-3d class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ $pool->name ?: __('Worker pool') }}</h3>
                            @if ($crossServer)
                                <span
                                    class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-inset ring-amber-200/70"
                                    title="{{ __('This pool runs a different server’s code and queues. It only drains this site’s jobs if they share the same queue connection/Redis.') }}"
                                >
                                    <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                                    {{ __('Different server') }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ trans_choice(':n worker|:n workers', $active, ['n' => $active]) }}
                            · {{ __('target') }} {{ $desired ?: $active }}
                            · {{ __('max') }} {{ $cap }}
                            · {{ $pool->status }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                        @if ($primary && ! $pool->isSiteSourced())
                            <a href="{{ $pool->workspaceUrl() }}" wire:navigate class="{{ $rowBtn }}">
                                {{ __('Pool settings') }}
                                <x-heroicon-m-arrow-up-right class="h-3 w-3" />
                            </a>
                        @endif
                        @can('update', $site)
                            @if ($pool->isSiteSourced())
                                <button
                                    type="button"
                                    wire:click="openDestroyFleetModal(@js((string) $pool->id))"
                                    class="{{ $rowBtn }}"
                                >{{ __('Destroy fleet') }}</button>
                            @elseif (in_array($pool->id, $explicitPoolIds, true))
                                <button
                                    type="button"
                                    wire:click="requestDetachWorkerPool(@js((string) $pool->id))"
                                    wire:loading.attr="disabled"
                                    class="{{ $rowBtn }}"
                                >{{ __('Detach') }}</button>
                            @endif
                        @endcan
                    </div>
                </div>

                @can('update', $site)
                    <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-5 py-2 sm:px-6">
                        <span class="text-xs font-medium text-brand-moss">{{ __('Scale to') }}</span>
                        <input type="number" min="1" max="{{ $cap }}" x-model.number="n" class="w-16 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink" />
                        <button type="button" x-on:click="$wire.scaleWorkerPool(@js((string) $pool->id), n)" wire:loading.attr="disabled" class="{{ $rowBtn }}">
                            {{ __('Apply') }}
                        </button>
                        <button type="button" wire:click="addPoolWorker(@js((string) $pool->id))" wire:loading.attr="disabled" class="{{ $rowBtn }}">
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('Add worker') }}
                        </button>
                    </div>
                @endcan

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-brand-ink/10 px-5 py-2 text-xs text-brand-moss sm:px-6">
                    <span><span class="font-semibold text-brand-ink">{{ $hz['pending'] ?? '—' }}</span> {{ __('in queue') }}</span>
                    <span><span class="font-semibold text-brand-ink">{{ $hz['jobs_per_minute'] ?? '—' }}</span> {{ __('jobs/min') }}</span>
                    <span>{{ __('processed') }} <span class="font-medium text-brand-ink">{{ $hz['processed'] ?? '—' }}</span></span>
                    <span>{{ __('failed') }} <span class="font-medium text-brand-ink">{{ $hz['failed'] ?? '—' }}</span></span>
                    <span>{{ trans_choice(':n process|:n processes', $poolProcs, ['n' => $poolProcs]) }}</span>
                    @if ($collectedAt)
                        <span class="text-brand-mist">{{ \Illuminate\Support\Carbon::parse($collectedAt)->diffForHumans() }}</span>
                    @endif
                    @can('update', $site)
                        <button type="button" wire:click="refreshWorkerStats(@js((string) $pool->id))" wire:loading.attr="disabled" wire:target="refreshWorkerStats" class="{{ $rowBtn }} ml-auto">
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="refreshWorkerStats" />
                            {{ __('Refresh') }}
                        </button>
                    @endcan
                </div>

                <ul class="divide-y divide-brand-ink/5">
                    @foreach ($members as $member)
                        @php
                            $mProcs = $workerProcs($member);
                            $share = $poolProcs > 0 ? (int) round($mProcs / $poolProcs * 100) : 0;
                            $memberFailed = $member->status === \App\Models\Server::STATUS_ERROR
                                || $member->poolMemberState() === \App\Models\WorkerPool::MEMBER_ERRORED;
                            $memberFailure = $memberFailed ? $this->workerProvisionFailure($member) : null;
                        @endphp
                        <li class="flex flex-wrap items-center gap-3 px-5 py-2 sm:px-6" wire:key="pool-member-{{ $member->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="openWorkerProcessModal(@js((string) $member->id))"
                                        class="truncate text-left text-sm font-medium text-brand-ink hover:text-brand-sage hover:underline"
                                    >{{ $member->name }}</button>
                                    @if ($member->isPoolPrimary())
                                        <span class="rounded-md bg-violet-100 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-violet-800">{{ __('primary') }}</span>
                                    @else
                                        <span class="rounded-md bg-brand-sand/70 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('replica') }}</span>
                                    @endif
                                    @if ($memberFailed)
                                        <span class="rounded-md bg-rose-100 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-rose-800">{{ __('failed') }}</span>
                                    @elseif ($member->poolMemberState())
                                        <span class="text-xs text-brand-mist">{{ $member->poolMemberState() }}</span>
                                    @endif
                                </div>
                                @if ($memberFailure)
                                    <p class="mt-0.5 text-xs text-rose-800">{{ $memberFailure['message'] }}</p>
                                @else
                                    <p class="mt-0.5 truncate font-mono text-xs text-brand-mist">{{ $member->ip_address ?? '—' }} · {{ $member->region ?? '—' }} · {{ $member->size ?? '—' }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-2" title="{{ __('Worker processes on this box — its share of the pool\'s drain capacity.') }}">
                                <span class="text-xs text-brand-moss">{{ trans_choice(':n process|:n processes', $mProcs, ['n' => $mProcs]) }}</span>
                                <div class="h-1.5 w-20 overflow-hidden rounded-full bg-brand-sand/60">
                                    <div class="h-full rounded-full bg-violet-500" style="width: {{ $share }}%"></div>
                                </div>
                                <span class="w-8 text-right text-xs font-medium tabular-nums text-brand-mist">{{ $share }}%</span>
                            </div>
                            <button
                                type="button"
                                wire:click="openWorkerProcessModal(@js((string) $member->id))"
                                class="{{ $rowBtn }} shrink-0"
                            >{{ $memberFailed ? __('View error') : ($member->isProvisioningComplete() && $member->poolMemberState() === \App\Models\WorkerPool::MEMBER_ACTIVE ? __('View process') : __('View install')) }}</button>
                            @if ($memberFailed && blank($member->provider_id))
                                <button
                                    type="button"
                                    wire:click="retryFailedWorkerProvision(@js((string) $member->id))"
                                    wire:loading.attr="disabled"
                                    class="{{ $rowBtn }} shrink-0"
                                >{{ __('Retry install') }}</button>
                            @endif
                            @can('update', $site)
                                @unless ($member->isPoolPrimary())
                                    <button
                                        type="button"
                                        wire:click="requestRemovePoolWorker(@js((string) $pool->id), @js((string) $member->id))"
                                        wire:loading.attr="disabled"
                                        class="inline-flex shrink-0 items-center rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50 disabled:opacity-50"
                                    >{{ __('Remove') }}</button>
                                @endunless
                            @endcan
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif

</div>

@if ($showAddWorkerModal)
    @teleport('body')
        <div
            class="fixed inset-0 isolate z-[100] overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="add-worker-modal-title"
            x-data="{
                close() {
                    document.body.classList.remove('overflow-y-hidden')
                    $wire.closeAddWorkerModal()
                },
            }"
            x-init="
                document.body.classList.add('overflow-y-hidden');
                return () => document.body.classList.remove('overflow-y-hidden')
            "
            x-on:keydown.escape.window="close()"
        >
            <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="close()"></div>
            <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                <x-dialog-shell :title="__('Add a worker')" title-id="add-worker-modal-title" max-width="lg" wire:init="loadAddWorkerCatalog">
                    <div class="space-y-4">
                        <p class="text-sm leading-relaxed text-brand-moss">{{ __('Provisions a worker-role VM of this site — same repo and queue, no webserver. Scheduler and migrations stay here.') }}</p>

                        @if (is_array($addWorkerPreflight))
                            <div @class([
                                'rounded-lg border px-3 py-2 text-xs leading-relaxed',
                                'border-emerald-200 bg-emerald-50 text-emerald-900' => $addWorkerPreflight['ok'],
                                'border-amber-200 bg-amber-50 text-amber-900' => ! $addWorkerPreflight['ok'],
                            ])>
                                <p class="font-semibold">{{ $addWorkerPreflight['ok'] ? __('Ready to provision') : __('Can’t add a worker yet') }}</p>
                                <p class="mt-1">{{ $addWorkerPreflight['message'] }}</p>
                            </div>
                        @endif

                        <div>
                            <x-input-label :value="__('Region')" />
                            @if ($addWorkerAllowsRemoteRegion && $addWorkerRegions !== [])
                                <div class="mt-1 max-h-40 space-y-1.5 overflow-y-auto pr-0.5">
                                    @foreach ($addWorkerRegions as $region)
                                        @php $selected = $addWorkerRegion === $region['value']; @endphp
                                        <button
                                            type="button"
                                            wire:click="selectAddWorkerRegion(@js($region['value']))"
                                            wire:loading.attr="disabled"
                                            wire:target="selectAddWorkerRegion,loadAddWorkerCatalog"
                                            @class([
                                                'flex w-full items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left text-sm',
                                                'border-brand-forest bg-brand-sand/40 text-brand-ink' => $selected,
                                                'border-brand-ink/10 bg-white text-brand-ink hover:bg-brand-sand/30' => ! $selected,
                                            ])
                                        >
                                            <span class="font-medium">{{ $region['label'] }}</span>
                                            @if ($region['value'] === $addWorkerSiteRegion)
                                                <span class="shrink-0 text-xs font-semibold text-brand-moss">{{ __('This site') }}</span>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                                <p class="mt-1.5 text-xs leading-relaxed text-brand-moss">
                                    @if ($addWorkerRegion !== '' && $addWorkerRegion !== $addWorkerSiteRegion)
                                        {{ __('This worker won’t join the site VPC. We’ll allow its public IP on the managed Redis/database. Expect a bit more latency.') }}
                                    @else
                                        {{ __('Default is this site’s region. Another location is fine — Redis and the database are managed, so a shared VPC is not required.') }}
                                    @endif
                                </p>
                            @else
                                <div class="mt-1 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                                    <p class="text-sm font-medium text-brand-ink">{{ $addWorkerRegionLabel }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">
                                        @if ($addWorkerAllowsRemoteRegion)
                                            {{ __('Loading regions…') }}
                                        @else
                                            {{ __('Locked to this site’s region and VPC so the worker can reach Redis and the database on the private network.') }}
                                        @endif
                                    </p>
                                </div>
                            @endif
                            <x-input-error :messages="$errors->get('addWorkerRegion')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label :value="__('Size')" />
                            @if ($addWorkerCatalogLoading)
                                <div class="mt-1 flex items-center justify-center rounded-lg border border-brand-ink/10 bg-white py-10">
                                    <x-spinner size="lg" variant="ink" />
                                </div>
                            @elseif ($addWorkerSizes !== [])
                                <div class="mt-1 max-h-56 space-y-1.5 overflow-y-auto pr-0.5">
                                    @foreach ($addWorkerSizes as $size)
                                        @php
                                            $selected = $addWorkerSize === $size['value'];
                                            $parts = array_filter([
                                                isset($size['vcpus']) ? $size['vcpus'].' vCPU' : null,
                                                isset($size['memory_mb']) ? ($size['memory_mb'] >= 1024 ? ((int) round($size['memory_mb'] / 1024)).' GB' : $size['memory_mb'].' MB') : null,
                                                isset($size['disk_gb']) ? $size['disk_gb'].' GB disk' : null,
                                            ]);
                                        @endphp
                                        <button
                                            type="button"
                                            wire:click="$set('addWorkerSize', @js($size['value']))"
                                            @class([
                                                'flex w-full items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left text-sm',
                                                'border-brand-forest bg-brand-sand/40 text-brand-ink' => $selected,
                                                'border-brand-ink/10 bg-white text-brand-ink hover:bg-brand-sand/30' => ! $selected,
                                            ])
                                        >
                                            <span>
                                                <span class="font-medium">{{ $size['value'] }}</span>
                                                @if ($parts !== [])
                                                    <span class="mt-0.5 block text-xs text-brand-moss">{{ implode(' · ', $parts) }}</span>
                                                @endif
                                            </span>
                                            @if (isset($size['price_monthly']))
                                                <span class="shrink-0 text-xs font-semibold text-brand-moss">${{ number_format($size['price_monthly'], $size['price_monthly'] < 10 ? 2 : 0) }}/mo</span>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            @else
                                <x-text-input wire:model="addWorkerSize" class="mt-1 block w-full font-mono text-sm" />
                                @if (filled($addWorkerCatalogError))
                                    <p class="mt-1 text-xs text-amber-800">{{ $addWorkerCatalogError }}</p>
                                @endif
                            @endif
                            <x-input-error :messages="$errors->get('addWorkerSize')" class="mt-1" />
                        </div>

                        <label class="flex items-start gap-2 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="addWorkerStopOnBox" class="mt-0.5 rounded border-brand-ink/15 text-brand-forest shadow-sm focus:ring-brand-forest">
                            <span>{{ __('Stop Horizon / queue workers on this web box once the first worker is healthy.') }}</span>
                        </label>
                    </div>

                    <x-slot:footer>
                        <x-secondary-button type="button" x-on:click="close()">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button
                            type="button"
                            wire:click="confirmAddWorker"
                            :disabled="! ($addWorkerPreflight['ok'] ?? false) || $addWorkerCatalogLoading || $addWorkerSize === ''"
                        >{{ __('Add worker') }}</x-primary-button>
                    </x-slot:footer>
                </x-dialog-shell>
            </div>
        </div>
    @endteleport
@endif

@if ($showDestroyFleetModal)
    @teleport('body')
        <div
            class="fixed inset-0 isolate z-[100] overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="destroy-fleet-modal-title"
            x-data="{
                close() {
                    document.body.classList.remove('overflow-y-hidden')
                    $wire.closeDestroyFleetModal()
                },
            }"
            x-init="
                document.body.classList.add('overflow-y-hidden');
                return () => document.body.classList.remove('overflow-y-hidden')
            "
            x-on:keydown.escape.window="close()"
        >
            <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="close()"></div>
            <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                <x-dialog-shell :title="__('Destroy worker fleet')" title-id="destroy-fleet-modal-title">
                    <div class="space-y-4">
                        <p class="text-sm leading-relaxed text-brand-moss">{{ __('Drain and destroy every worker VM in this fleet. You cannot scale to zero — this is how the fleet goes away.') }}</p>
                        <label class="flex items-start gap-2 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="destroyFleetRestoreOnBox" class="mt-0.5 rounded border-brand-ink/15 text-brand-forest shadow-sm focus:ring-brand-forest">
                            <span>{{ __('Start Horizon / queue workers on this web box again so jobs keep draining.') }}</span>
                        </label>
                    </div>
                    <x-slot:footer>
                        <x-secondary-button type="button" x-on:click="close()">{{ __('Cancel') }}</x-secondary-button>
                        <x-danger-button type="button" wire:click="confirmDestroyFleet">{{ __('Destroy fleet') }}</x-danger-button>
                    </x-slot:footer>
                </x-dialog-shell>
            </div>
        </div>
    @endteleport
@endif

@if ($showWorkerProcessModal)
    @php
        $processMember = $this->workerProcessMember();
        $processDeploy = $this->workerProcessDeployment();
        $processScale = $this->fleetScaleRun();
        $processFailed = $processMember && (
            $processMember->status === \App\Models\Server::STATUS_ERROR
            || $processMember->poolMemberState() === \App\Models\WorkerPool::MEMBER_ERRORED
        );
        $processFailure = $processFailed ? $this->workerProvisionFailure($processMember) : null;
        $installing = $processMember && ! $processFailed && ! $processMember->isProvisioningComplete();
        $deploying = $processMember && ! $processFailed && in_array($processMember->poolMemberState(), [
            \App\Models\WorkerPool::MEMBER_PROVISIONING,
            \App\Models\WorkerPool::MEMBER_REPLAYING,
            \App\Models\WorkerPool::MEMBER_DEPLOYING,
        ], true);
    @endphp
    @teleport('body')
        <div
            class="fixed inset-0 isolate z-[100] overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="worker-process-modal-title"
            x-data="{
                close() {
                    document.body.classList.remove('overflow-y-hidden')
                    $wire.closeWorkerProcessModal()
                },
            }"
            x-init="
                document.body.classList.add('overflow-y-hidden');
                return () => document.body.classList.remove('overflow-y-hidden')
            "
            x-on:keydown.escape.window="close()"
        >
            <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="close()"></div>
            <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                <x-dialog-shell
                    :title="$processMember?->name ?: __('Worker process')"
                    title-id="worker-process-modal-title"
                    max-width="2xl"
                >
                    @if ($installing || $deploying || ($processScale && $processScale->isInFlight()))
                        <div wire:poll.4s class="hidden" aria-hidden="true"></div>
                    @endif

                    @if (! $processMember)
                        <p class="text-sm text-brand-moss">{{ __('That worker is no longer in this fleet.') }}</p>
                    @else
                        <div class="space-y-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm text-brand-moss">
                                    {{ $processFailed
                                        ? __('Install stopped before DigitalOcean created a droplet.')
                                        : ($installing
                                            ? __('Installing the worker VM — cloud create, SSH, and the queue-worker setup script.')
                                            : ($deploying
                                                ? __('The box is up. Deploying this site’s release and starting queue workers.')
                                                : __('Install and deploy for this worker.'))) }}
                                </p>
                                <a
                                    href="{{ route('servers.show', $processMember) }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    {{ $installing || $processFailed ? __('Open full install') : __('Open server') }}
                                    <x-heroicon-m-arrow-up-right class="h-3 w-3" />
                                </a>
                            </div>

                            @if ($processFailure)
                                <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
                                    <p class="font-semibold">{{ __('Provisioning failed') }}</p>
                                    <p class="mt-1">{{ $processFailure['message'] }}</p>
                                    @if ($processFailure['authFailed'])
                                        <p class="mt-2 text-xs leading-relaxed">{{ __('No droplet was created. Reconnect the DigitalOcean token, then retry.') }}</p>
                                    @endif
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @if (blank($processMember->provider_id))
                                            <x-primary-button type="button" wire:click="retryFailedWorkerProvision(@js((string) $processMember->id))">{{ __('Retry install') }}</x-primary-button>
                                        @endif
                                        <a href="{{ route('credentials.index') }}" wire:navigate class="inline-flex items-center rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50">{{ __('Open credentials') }}</a>
                                    </div>
                                </div>
                            @endif

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Fleet progress') }}</p>
                                <div class="mt-1.5 overflow-hidden rounded-lg border border-brand-ink/10">
                                    @if ($processScale)
                                        @include('livewire.partials.console-action-banner-static', [
                                            'run' => $processScale,
                                            'kindLabels' => (array) config('console_actions.kinds', []),
                                            'embedded' => true,
                                        ])
                                    @else
                                        <p class="px-3 py-2 text-sm text-brand-moss">{{ __('No fleet console yet — output appears as soon as provision starts.') }}</p>
                                    @endif
                                </div>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Site deploy') }}</p>
                                @if ($processDeploy)
                                    <div class="mt-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                                        <p class="text-sm font-medium text-brand-ink">
                                            {{ __('Status') }}
                                            <span class="font-normal text-brand-moss">{{ $processDeploy->status }}</span>
                                            @if ($processDeploy->git_sha)
                                                <span class="font-mono text-xs text-brand-mist">{{ \Illuminate\Support\Str::limit((string) $processDeploy->git_sha, 7, '') }}</span>
                                            @endif
                                        </p>
                                        @if (filled($processDeploy->log_output))
                                            <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">{{ $processDeploy->log_output }}</pre>
                                        @else
                                            <p class="mt-1 text-xs text-brand-moss">{{ __('Waiting for deploy output…') }}</p>
                                        @endif
                                    </div>
                                @else
                                    <p class="mt-1.5 text-sm text-brand-moss">{{ $installing
                                        ? __('Deploy starts after the worker VM finishes installing.')
                                        : __('No deploy recorded on this worker yet.') }}</p>
                                @endif
                            </div>
                        </div>
                    @endif

                    <x-slot:footer>
                        <x-secondary-button type="button" x-on:click="close()">{{ __('Close') }}</x-secondary-button>
                    </x-slot:footer>
                </x-dialog-shell>
            </div>
        </div>
    @endteleport
@endif
