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


        <x-slot:footer>
            <x-cli-snippet :commands="[
                ['command' => 'dply ops:doctor'],
            ]" />
        </x-slot:footer>
    </x-infrastructure-shell>
</div>
