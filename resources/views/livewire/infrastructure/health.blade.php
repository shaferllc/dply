<div>
    <x-infrastructure-shell
        :title="__('Health')"
        :description="__('Drift, in-flight deploys, and failure surfaces across the :org organization.', ['org' => $org->name])"
        :section="__('Health')"
        icon="heroicon-o-heart"
    >
        <section class="border-b border-brand-ink/10 px-5 py-5 sm:px-6" aria-label="{{ __('Health summary') }}">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <x-infrastructure-stat :label="__('Servers')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $serverCount }}</p>
                    @if ($drift['servers_with_drift'] > 0)
                        <p class="mt-1 text-xs text-rose-600">{{ trans_choice('{1} 1 with drift|[2,*] :count with drift', $drift['servers_with_drift'], ['count' => $drift['servers_with_drift']]) }}</p>
                    @else
                        <p class="mt-1 text-xs text-emerald-600">{{ __('No drift') }}</p>
                    @endif
                </x-infrastructure-stat>
                <x-infrastructure-stat :label="__('Sites')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $siteCount }}</p>
                </x-infrastructure-stat>
                <x-infrastructure-stat :label="__('Running deploys')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $deploys['running'] }}</p>
                    @if ($deploys['long_running'] > 0)
                        <p class="mt-1 text-xs text-amber-600">{{ trans_choice('{1} 1 longer than 15m|[2,*] :count longer than 15m', $deploys['long_running'], ['count' => $deploys['long_running']]) }}</p>
                    @endif
                </x-infrastructure-stat>
                <x-infrastructure-stat :label="__('Failed (latest)')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums {{ count($deploys['failed_latest']) > 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ count($deploys['failed_latest']) }}</p>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Sites whose last deploy failed') }}</p>
                </x-infrastructure-stat>
                <x-infrastructure-stat :label="__(':days-day success', ['days' => $successRate['window_days']])">
                    @if ($successRate['percent'] === null)
                        <p class="mt-2 text-3xl font-semibold text-brand-mist">—</p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('No deploys yet') }}</p>
                    @else
                        <p class="mt-2 text-3xl font-semibold tabular-nums {{ $successRate['percent'] >= 95 ? 'text-emerald-600' : ($successRate['percent'] >= 80 ? 'text-amber-600' : 'text-rose-600') }}">{{ $successRate['percent'] }}%</p>
                        <p class="mt-1 text-xs text-brand-mist">{{ $successRate['success'] }} / {{ $successRate['total'] }} {{ __('settled') }}</p>
                    @endif
                </x-infrastructure-stat>
            </div>
        </section>

        @if ($drift['sites_with_unregistered_engine'] !== [] || $drift['sites_needing_runtime_install'] !== [])
            <section class="border-b border-brand-ink/10">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/50 px-5 py-4 sm:px-6">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-900 ring-1 ring-amber-200">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Drift detected') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Engines and runtimes that need attention across the organization.') }}</p>
                    </div>
                </div>
                <div class="space-y-5 px-5 py-5 sm:px-6">
                    @if ($drift['sites_with_unregistered_engine'] !== [])
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Sites pinned to engines NOT registered on their server') }}</h3>
                            <ul class="mt-2 divide-y divide-brand-ink/10 border-y border-brand-ink/10">
                                @foreach ($drift['sites_with_unregistered_engine'] as $row)
                                    <li class="px-1 py-2 text-sm text-brand-ink">
                                        <span class="font-medium">{{ $row['site'] }}</span>
                                        <span class="text-brand-moss">→ {{ $row['engine'] }}</span>
                                        <span class="text-brand-mist">on {{ $row['server'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($drift['sites_needing_runtime_install'] !== [])
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Sites with non-pinned runtimes') }}</h3>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('mise installs on demand, but pinning is faster.') }}</p>
                            <ul class="mt-2 divide-y divide-brand-ink/10 border-y border-brand-ink/10">
                                @foreach ($drift['sites_needing_runtime_install'] as $row)
                                    <li class="px-1 py-2 text-sm text-brand-ink">
                                        <span class="font-medium">{{ $row['site'] }}</span>
                                        <span class="text-brand-moss">→ {{ $row['runtime'] }}</span>
                                        <span class="text-brand-mist">on {{ $row['server'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <x-cli-snippet :commands="[
                        ['command' => 'dply ops:doctor'],
                    ]" />
                </div>
            </section>
        @endif

        @if ($deploys['failed_latest'] !== [])
            <section class="border-b border-brand-ink/10">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-rose-50/40 px-5 py-4 sm:px-6">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-900 ring-1 ring-rose-200">
                        <x-heroicon-o-x-circle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Sites with failed latest deploy') }}</h2>
                    </div>
                </div>
                <div class="px-5 py-5 sm:px-6">
                    <ul class="divide-y divide-brand-ink/10 border-y border-brand-ink/10 text-sm">
                        @foreach ($deploys['failed_latest'] as $row)
                            <li class="px-1 py-2 text-brand-ink">
                                <span class="font-medium">{{ $row['site'] }}</span>
                                @if ($row['finished_at'])
                                    <span class="text-brand-moss">at {{ $row['finished_at'] }}</span>
                                @endif
                                <span class="ml-2 select-all rounded bg-brand-sand/40 px-1.5 py-0.5 font-mono text-2xs text-brand-mist">{{ $row['deployment_id'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-4">
                        <x-cli-snippet :commands="[
                            ['command' => 'dply ops:failed-deploys'],
                        ]" />
                    </div>
                </div>
            </section>
        @endif

        @if ($mostActive !== [])
            <section class="border-b border-brand-ink/10">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Most active sites (30 days)') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Top 5 by settled deploy count.') }}</p>
                    </div>
                </div>
                <ul class="divide-y divide-brand-ink/10 px-5 sm:px-6">
                    @foreach ($mostActive as $row)
                        <li class="flex items-center justify-between py-3 text-sm">
                            <a href="{{ route('sites.show', ['server' => $row['server_id'], 'site' => $row['site']]) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-forest">{{ $row['site']->name }}</a>
                            <span class="font-mono text-xs text-brand-moss">{{ $row['count'] }} {{ __('deploys') }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($drift['servers_with_drift'] === 0 && $deploys['failed_latest'] === [] && $deploys['long_running'] === 0)
            <section class="border-b border-brand-ink/10 bg-emerald-50/40 px-5 py-8 text-center sm:px-6">
                <p class="text-base font-semibold text-emerald-900">{{ __('All clear') }}</p>
                <p class="mt-1 text-sm text-emerald-800">{{ __('No drift, no failed latest deploys, no stuck running deploys.') }}</p>
            </section>
        @endif

        @if ($cloudSummary)
            <section class="border-b border-brand-ink/10">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-sky-50/40 px-5 py-4 sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-800 ring-1 ring-sky-200">
                            <x-heroicon-o-cloud class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-700">{{ __('Dply cloud') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ trans_choice('{1} 1 cloud container site|[2,*] :count cloud container sites', $cloudSummary['total'], ['count' => $cloudSummary['total']]) }}
                            </h2>
                        </div>
                    </div>
                    <a href="{{ route('cloud.index') }}" wire:navigate class="rounded-xl bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-800">
                        {{ __('Open /cloud') }} →
                    </a>
                </div>

                <div class="px-5 py-5 sm:px-6">
                    <dl class="grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($cloudSummary['by_backend'] as $backend => $count)
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-3">
                                <dt class="font-semibold uppercase tracking-[0.14em] text-brand-mist">
                                    {{ $backend === 'digitalocean_app_platform' ? 'DO App Platform' : ($backend === 'aws_app_runner' ? 'AWS App Runner' : $backend) }}
                                </dt>
                                <dd class="mt-1 text-lg font-semibold text-brand-ink">{{ $count }}</dd>
                            </div>
                        @endforeach
                        @php
                            $byStatus = $cloudSummary['by_status'];
                            $activeCount = $byStatus[\App\Models\Site::STATUS_CONTAINER_ACTIVE] ?? 0;
                            $provisioningCount = $byStatus[\App\Models\Site::STATUS_CONTAINER_PROVISIONING] ?? 0;
                            $failedCount = $byStatus[\App\Models\Site::STATUS_CONTAINER_FAILED] ?? 0;
                        @endphp
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-3">
                            <dt class="font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Active') }}</dt>
                            <dd class="mt-1 text-lg font-semibold {{ $activeCount > 0 ? 'text-emerald-600' : 'text-brand-mist' }}">{{ $activeCount }}</dd>
                        </div>
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-3">
                            <dt class="font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('In flight') }}</dt>
                            <dd class="mt-1 text-lg font-semibold {{ $provisioningCount > 0 ? 'text-sky-700' : 'text-brand-mist' }}">{{ $provisioningCount }}</dd>
                        </div>
                    </dl>

                    @php
                        $sourceCount = $cloudSummary['by_mode']['source'] ?? 0;
                        $imageCount = $cloudSummary['by_mode']['image'] ?? 0;
                        $previewCount = $cloudSummary['previews'] ?? 0;
                    @endphp
                    @if ($sourceCount > 0 || $previewCount > 0)
                        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-brand-moss">
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

                    @if ($cloudSummary['failed_sites'] !== [])
                        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50/60 p-3 text-xs text-rose-900">
                            <p class="font-semibold">
                                {{ trans_choice('{1} 1 cloud site failed|[2,*] :count cloud sites failed', $failedCount, ['count' => $failedCount]) }}
                            </p>
                            <ul class="mt-1 space-y-0.5">
                                @foreach ($cloudSummary['failed_sites'] as $row)
                                    <li>
                                        <span class="font-medium">{{ $row['name'] }}</span>
                                        @if ($row['container_image'])
                                            <span class="ml-1 font-mono text-xs text-rose-700">{{ $row['container_image'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </section>
        @elseif ($cloudUpsell)
            <section class="border-b border-brand-ink/10 bg-sky-50/40 px-5 py-5 sm:px-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-sky-900">{{ __('Deploy a container app on dply cloud') }}</p>
                        <p class="mt-1 max-w-2xl text-xs text-sky-800">
                            {{ __('Run any container image globally on dply cloud — managed HTTPS, auto-scaling, and one-click rollback. Backed by DigitalOcean App Platform or AWS App Runner.') }}
                        </p>
                    </div>
                    <a href="{{ route('cloud.create') }}" wire:navigate class="shrink-0 rounded-xl bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-800">
                        {{ __('Deploy to dply cloud') }} →
                    </a>
                </div>
            </section>
        @endif

        <x-slot:footer>
            <x-cli-snippet :commands="[
                ['command' => 'dply ops:doctor'],
            ]" />
        </x-slot:footer>
    </x-infrastructure-shell>
</div>
