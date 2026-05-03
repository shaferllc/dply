<div class="mx-auto max-w-6xl px-6 py-10">
    @include('livewire.fleet._tabs')
    <header class="mb-6 border-b border-slate-200 pb-4">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('Fleet health') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('Drift, in-flight deploys, and failure surfaces across the :org organization.', ['org' => $org->name]) }}</p>
    </header>

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Servers') }}</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $serverCount }}</p>
            @if ($drift['servers_with_drift'] > 0)
                <p class="mt-1 text-xs text-rose-700">{{ trans_choice('{1} 1 with drift|[2,*] :count with drift', $drift['servers_with_drift'], ['count' => $drift['servers_with_drift']]) }}</p>
            @else
                <p class="mt-1 text-xs text-emerald-700">{{ __('No drift') }}</p>
            @endif
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Sites') }}</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $siteCount }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Running deploys') }}</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $deploys['running'] }}</p>
            @if ($deploys['long_running'] > 0)
                <p class="mt-1 text-xs text-amber-700">{{ trans_choice('{1} 1 longer than 15m|[2,*] :count longer than 15m', $deploys['long_running'], ['count' => $deploys['long_running']]) }}</p>
            @endif
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Failed (latest)') }}</p>
            <p class="mt-2 text-3xl font-semibold {{ count($deploys['failed_latest']) > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ count($deploys['failed_latest']) }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Sites whose last deploy failed') }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __(':days-day success', ['days' => $successRate['window_days']]) }}</p>
            @if ($successRate['percent'] === null)
                <p class="mt-2 text-3xl font-semibold text-slate-400">—</p>
                <p class="mt-1 text-xs text-slate-500">{{ __('No deploys yet') }}</p>
            @else
                <p class="mt-2 text-3xl font-semibold {{ $successRate['percent'] >= 95 ? 'text-emerald-700' : ($successRate['percent'] >= 80 ? 'text-amber-700' : 'text-rose-700') }}">{{ $successRate['percent'] }}%</p>
                <p class="mt-1 text-xs text-slate-500">{{ $successRate['success'] }} / {{ $successRate['total'] }} {{ __('settled') }}</p>
            @endif
        </div>
    </section>

    @if ($drift['sites_with_unregistered_engine'] !== [] || $drift['sites_needing_runtime_install'] !== [])
        <section class="mt-8 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Drift detected') }}</h2>
            @if ($drift['sites_with_unregistered_engine'] !== [])
                <div class="mt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Sites pinned to engines NOT registered on their server') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($drift['sites_with_unregistered_engine'] as $row)
                            <li class="rounded bg-white px-3 py-1.5 text-slate-800">
                                <span class="font-medium">{{ $row['site'] }}</span>
                                <span class="text-slate-500">→ {{ $row['engine'] }}</span>
                                <span class="text-slate-400">on {{ $row['server'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($drift['sites_needing_runtime_install'] !== [])
                <div class="mt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Sites with non-pinned runtimes') }}</h3>
                    <p class="mt-1 text-xs text-slate-500">{{ __('mise installs on demand, but pinning is faster.') }}</p>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($drift['sites_needing_runtime_install'] as $row)
                            <li class="rounded bg-white px-3 py-1.5 text-slate-800">
                                <span class="font-medium">{{ $row['site'] }}</span>
                                <span class="text-slate-500">→ {{ $row['runtime'] }}</span>
                                <span class="text-slate-400">on {{ $row['server'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <p class="mt-4 text-xs text-slate-600">
                {{ __('From the terminal:') }}
                <code class="ml-1 select-all rounded bg-white px-1 py-0.5 font-mono">dply:fleet:doctor</code>
            </p>
        </section>
    @endif

    @if ($deploys['failed_latest'] !== [])
        <section class="mt-8 rounded-2xl border border-rose-200 bg-rose-50/60 p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Sites with failed latest deploy') }}</h2>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($deploys['failed_latest'] as $row)
                    <li class="rounded bg-white px-3 py-1.5 text-slate-800">
                        <span class="font-medium">{{ $row['site'] }}</span>
                        @if ($row['finished_at'])
                            <span class="text-slate-500">at {{ $row['finished_at'] }}</span>
                        @endif
                        <span class="ml-2 select-all rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-500">{{ $row['deployment_id'] }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-xs text-slate-600">
                {{ __('From the terminal:') }}
                <code class="ml-1 select-all rounded bg-white px-1 py-0.5 font-mono">dply:fleet:failed-deploys</code>
            </p>
        </section>
    @endif

    @if ($mostActive !== [])
        <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Most active sites (30 days)') }}</h2>
            <p class="mt-1 text-xs text-slate-500">{{ __('Top 5 by settled deploy count.') }}</p>
            <ul class="mt-3 divide-y divide-slate-100">
                @foreach ($mostActive as $row)
                    <li class="flex items-center justify-between py-2 text-sm">
                        <a href="{{ route('sites.show', ['server' => $row['server_id'], 'site' => $row['site']]) }}" wire:navigate class="font-medium text-slate-800 hover:underline">{{ $row['site']->name }}</a>
                        <span class="font-mono text-xs text-slate-500">{{ $row['count'] }} {{ __('deploys') }}</span>
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

    @if ($edgeFleet)
        <section class="mt-8 rounded-2xl border border-sky-200 bg-sky-50/40 p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700">{{ __('Dply edge') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-slate-900">
                        {{ trans_choice('{1} 1 edge container site|[2,*] :count edge container sites', $edgeFleet['total'], ['count' => $edgeFleet['total']]) }}
                    </h2>
                </div>
                <a href="{{ route('edge.index') }}" wire:navigate class="rounded-xl bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-800">
                    {{ __('Open /edge') }} →
                </a>
            </div>

            <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($edgeFleet['by_backend'] as $backend => $count)
                    <div class="rounded-xl border border-slate-200 bg-white p-3">
                        <dt class="font-semibold uppercase tracking-[0.14em] text-slate-500">
                            {{ $backend === 'digitalocean_app_platform' ? 'DO App Platform' : ($backend === 'aws_app_runner' ? 'AWS App Runner' : $backend) }}
                        </dt>
                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ $count }}</dd>
                    </div>
                @endforeach
                @php
                    $byStatus = $edgeFleet['by_status'];
                    $activeCount = $byStatus[\App\Models\Site::STATUS_CONTAINER_ACTIVE] ?? 0;
                    $provisioningCount = $byStatus[\App\Models\Site::STATUS_CONTAINER_PROVISIONING] ?? 0;
                    $failedCount = $byStatus[\App\Models\Site::STATUS_CONTAINER_FAILED] ?? 0;
                @endphp
                <div class="rounded-xl border border-slate-200 bg-white p-3">
                    <dt class="font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Active') }}</dt>
                    <dd class="mt-1 text-lg font-semibold {{ $activeCount > 0 ? 'text-emerald-700' : 'text-slate-400' }}">{{ $activeCount }}</dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3">
                    <dt class="font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('In flight') }}</dt>
                    <dd class="mt-1 text-lg font-semibold {{ $provisioningCount > 0 ? 'text-sky-700' : 'text-slate-400' }}">{{ $provisioningCount }}</dd>
                </div>
            </dl>

            @php
                $sourceCount = $edgeFleet['by_mode']['source'] ?? 0;
                $imageCount = $edgeFleet['by_mode']['image'] ?? 0;
                $previewCount = $edgeFleet['previews'] ?? 0;
            @endphp
            @if ($sourceCount > 0 || $previewCount > 0)
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                    @if ($sourceCount > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2.5 py-1 font-medium text-indigo-800">
                            <span class="size-1.5 rounded-full bg-indigo-500"></span>
                            {{ trans_choice('{1} 1 source-mode site|[2,*] :count source-mode sites', $sourceCount, ['count' => $sourceCount]) }}
                        </span>
                    @endif
                    @if ($imageCount > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">
                            <span class="size-1.5 rounded-full bg-slate-400"></span>
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

            @if ($edgeFleet['failed_sites'] !== [])
                <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50/60 p-3 text-xs text-rose-900">
                    <p class="font-semibold">
                        {{ trans_choice('{1} 1 edge site failed|[2,*] :count edge sites failed', $failedCount, ['count' => $failedCount]) }}
                    </p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($edgeFleet['failed_sites'] as $row)
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
    @elseif ($flyUpsell)
        <section class="mt-8 rounded-2xl border border-sky-200 bg-sky-50/60 p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-sky-900">{{ __('Deploy a container app on dply edge') }}</p>
                    <p class="mt-1 text-xs text-sky-800">
                        {{ __('Run any container image globally on dply edge — managed HTTPS, auto-scaling, and one-click rollback. Backed by DigitalOcean App Platform or AWS App Runner.') }}
                    </p>
                </div>
                <a href="{{ route('edge.create') }}" wire:navigate class="rounded-xl bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-800">
                    {{ __('Deploy to dply edge') }} →
                </a>
            </div>
        </section>
    @endif

    <footer class="mt-8 text-xs text-slate-500">
        {{ __('Same data is available from the terminal:') }}
        <code class="ml-1 select-all rounded bg-slate-100 px-1 py-0.5 font-mono">dply:fleet:doctor</code>
    </footer>
</div>
