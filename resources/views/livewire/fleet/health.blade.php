<div>
    <x-fleet-shell
        :title="__('Fleet health')"
        :description="__('Drift, in-flight deploys, and failure surfaces across the :org organization.', ['org' => $org->name])"
        :section="__('Health')"
    >
    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-fleet-stat :label="__('Servers')">
            <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $serverCount }}</p>
            @if ($drift['servers_with_drift'] > 0)
                <p class="mt-1 text-xs text-rose-600">{{ trans_choice('{1} 1 with drift|[2,*] :count with drift', $drift['servers_with_drift'], ['count' => $drift['servers_with_drift']]) }}</p>
            @else
                <p class="mt-1 text-xs text-emerald-600">{{ __('No drift') }}</p>
            @endif
        </x-fleet-stat>
        <x-fleet-stat :label="__('Sites')">
            <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $siteCount }}</p>
        </x-fleet-stat>
        <x-fleet-stat :label="__('Running deploys')">
            <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $deploys['running'] }}</p>
            @if ($deploys['long_running'] > 0)
                <p class="mt-1 text-xs text-amber-600">{{ trans_choice('{1} 1 longer than 15m|[2,*] :count longer than 15m', $deploys['long_running'], ['count' => $deploys['long_running']]) }}</p>
            @endif
        </x-fleet-stat>
        <x-fleet-stat :label="__('Failed (latest)')">
            <p class="mt-2 text-3xl font-semibold tabular-nums {{ count($deploys['failed_latest']) > 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ count($deploys['failed_latest']) }}</p>
            <p class="mt-1 text-xs text-brand-mist">{{ __('Sites whose last deploy failed') }}</p>
        </x-fleet-stat>
        <x-fleet-stat :label="__(':days-day success', ['days' => $successRate['window_days']])">
            @if ($successRate['percent'] === null)
                <p class="mt-2 text-3xl font-semibold text-brand-mist">—</p>
                <p class="mt-1 text-xs text-brand-mist">{{ __('No deploys yet') }}</p>
            @else
                <p class="mt-2 text-3xl font-semibold tabular-nums {{ $successRate['percent'] >= 95 ? 'text-emerald-600' : ($successRate['percent'] >= 80 ? 'text-amber-600' : 'text-rose-600') }}">{{ $successRate['percent'] }}%</p>
                <p class="mt-1 text-xs text-brand-mist">{{ $successRate['success'] }} / {{ $successRate['total'] }} {{ __('settled') }}</p>
            @endif
        </x-fleet-stat>
    </section>

    @if ($drift['sites_with_unregistered_engine'] !== [] || $drift['sites_needing_runtime_install'] !== [])
        <section class="mt-8 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Drift detected') }}</h2>
            @if ($drift['sites_with_unregistered_engine'] !== [])
                <div class="mt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Sites pinned to engines NOT registered on their server') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($drift['sites_with_unregistered_engine'] as $row)
                            <li class="rounded-lg bg-white px-3 py-1.5 text-brand-ink">
                                <span class="font-medium">{{ $row['site'] }}</span>
                                <span class="text-brand-moss">→ {{ $row['engine'] }}</span>
                                <span class="text-brand-mist">on {{ $row['server'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($drift['sites_needing_runtime_install'] !== [])
                <div class="mt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Sites with non-pinned runtimes') }}</h3>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('mise installs on demand, but pinning is faster.') }}</p>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($drift['sites_needing_runtime_install'] as $row)
                            <li class="rounded-lg bg-white px-3 py-1.5 text-brand-ink">
                                <span class="font-medium">{{ $row['site'] }}</span>
                                <span class="text-brand-moss">→ {{ $row['runtime'] }}</span>
                                <span class="text-brand-mist">on {{ $row['server'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <x-cli-snippet class="mt-4" command="dply fleet:doctor" />
        </section>
    @endif

    @if ($deploys['failed_latest'] !== [])
        <section class="mt-8 rounded-2xl border border-rose-200 bg-rose-50/60 p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Sites with failed latest deploy') }}</h2>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($deploys['failed_latest'] as $row)
                    <li class="rounded-lg bg-white px-3 py-1.5 text-brand-ink">
                        <span class="font-medium">{{ $row['site'] }}</span>
                        @if ($row['finished_at'])
                            <span class="text-brand-moss">at {{ $row['finished_at'] }}</span>
                        @endif
                        <span class="ml-2 select-all rounded bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] text-brand-mist">{{ $row['deployment_id'] }}</span>
                    </li>
                @endforeach
            </ul>
            <x-cli-snippet class="mt-4" command="dply fleet:deploys:failed" />
        </section>
    @endif

    @if ($mostActive !== [])
        <section class="mt-8 dply-card p-5">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Most active sites (30 days)') }}</h2>
            <p class="mt-1 text-xs text-brand-mist">{{ __('Top 5 by settled deploy count.') }}</p>
            <ul class="mt-3 divide-y divide-brand-ink/10">
                @foreach ($mostActive as $row)
                    <li class="flex items-center justify-between py-2 text-sm">
                        <a href="{{ route('sites.show', ['server' => $row['server_id'], 'site' => $row['site']]) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-forest">{{ $row['site']->name }}</a>
                        <span class="font-mono text-xs text-brand-moss">{{ $row['count'] }} {{ __('deploys') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($drift['servers_with_drift'] === 0 && $deploys['failed_latest'] === [] && $deploys['long_running'] === 0)
        <section class="mt-8 rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5 text-center shadow-sm">
            <p class="text-lg font-semibold text-emerald-900">{{ __('All clear') }}</p>
            <p class="mt-1 text-sm text-emerald-800">{{ __('No drift, no failed latest deploys, no stuck running deploys.') }}</p>
        </section>
    @endif

    @if ($cloudFleet)
        <section class="mt-8 rounded-2xl border border-sky-200 bg-sky-50/40 p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700">{{ __('Dply cloud') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">
                        {{ trans_choice('{1} 1 cloud container site|[2,*] :count cloud container sites', $cloudFleet['total'], ['count' => $cloudFleet['total']]) }}
                    </h2>
                </div>
                <a href="{{ route('cloud.index') }}" wire:navigate class="rounded-xl bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-800">
                    {{ __('Open /cloud') }} →
                </a>
            </div>

            <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($cloudFleet['by_backend'] as $backend => $count)
                    <div class="rounded-xl border border-brand-ink/10 bg-white p-3">
                        <dt class="font-semibold uppercase tracking-[0.14em] text-brand-mist">
                            {{ $backend === 'digitalocean_app_platform' ? 'DO App Platform' : ($backend === 'aws_app_runner' ? 'AWS App Runner' : $backend) }}
                        </dt>
                        <dd class="mt-1 text-lg font-semibold text-brand-ink">{{ $count }}</dd>
                    </div>
                @endforeach
                @php
                    $byStatus = $cloudFleet['by_status'];
                    $activeCount = $byStatus[\App\Models\Site::STATUS_CONTAINER_ACTIVE] ?? 0;
                    $provisioningCount = $byStatus[\App\Models\Site::STATUS_CONTAINER_PROVISIONING] ?? 0;
                    $failedCount = $byStatus[\App\Models\Site::STATUS_CONTAINER_FAILED] ?? 0;
                @endphp
                <div class="rounded-xl border border-brand-ink/10 bg-white p-3">
                    <dt class="font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Active') }}</dt>
                    <dd class="mt-1 text-lg font-semibold {{ $activeCount > 0 ? 'text-emerald-600' : 'text-brand-mist' }}">{{ $activeCount }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white p-3">
                    <dt class="font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('In flight') }}</dt>
                    <dd class="mt-1 text-lg font-semibold {{ $provisioningCount > 0 ? 'text-sky-700' : 'text-brand-mist' }}">{{ $provisioningCount }}</dd>
                </div>
            </dl>

            @php
                $sourceCount = $cloudFleet['by_mode']['source'] ?? 0;
                $imageCount = $cloudFleet['by_mode']['image'] ?? 0;
                $previewCount = $cloudFleet['previews'] ?? 0;
            @endphp
            @if ($sourceCount > 0 || $previewCount > 0)
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-brand-moss">
                    @if ($sourceCount > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2.5 py-1 font-medium text-indigo-800">
                            <span class="size-1.5 rounded-full bg-indigo-500"></span>
                            {{ trans_choice('{1} 1 source-mode site|[2,*] :count source-mode sites', $sourceCount, ['count' => $sourceCount]) }}
                        </span>
                    @endif
                    @if ($imageCount > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2.5 py-1 font-medium text-brand-moss">
                            <span class="size-1.5 rounded-full bg-brand-mist"></span>
                            {{ trans_choice('{1} 1 image-mode site|[2,*] :count image-mode sites', $imageCount, ['count' => $imageCount]) }}
                        </span>
                    @endif
                    @if ($previewCount > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 font-medium text-amber-900">
                            <span class="size-1.5 rounded-full bg-amber-500"></span>
                            {{ trans_choice('{1} 1 preview deploy|[2,*] :count preview deploys', $previewCount, ['count' => $previewCount]) }}
                        </span>
                    @endif
                </div>
            @endif

            @if ($cloudFleet['failed_sites'] !== [])
                <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50/60 p-3 text-xs text-rose-900">
                    <p class="font-semibold">
                        {{ trans_choice('{1} 1 cloud site failed|[2,*] :count cloud sites failed', $failedCount, ['count' => $failedCount]) }}
                    </p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($cloudFleet['failed_sites'] as $row)
                            <li>
                                <span class="font-medium">{{ $row['name'] }}</span>
                                @if ($row['container_image'])
                                    <span class="ml-1 font-mono text-[11px] text-rose-700">{{ $row['container_image'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>
    @elseif ($cloudUpsell)
        <section class="mt-8 rounded-2xl border border-sky-200 bg-sky-50/60 p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-sky-900">{{ __('Deploy a container app on dply cloud') }}</p>
                    <p class="mt-1 text-xs text-sky-800">
                        {{ __('Run any container image globally on dply cloud — managed HTTPS, auto-scaling, and one-click rollback. Backed by DigitalOcean App Platform or AWS App Runner.') }}
                    </p>
                </div>
                <a href="{{ route('cloud.create') }}" wire:navigate class="rounded-xl bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-800">
                    {{ __('Deploy to dply cloud') }} →
                </a>
            </div>
        </section>
    @endif

    <x-cli-snippet class="mt-8" command="dply fleet:doctor" />
    </x-fleet-shell>
</div>
