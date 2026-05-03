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

    @if ($flyUpsell)
        <section class="mt-8 rounded-2xl border border-sky-200 bg-sky-50/60 p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-sky-900">{{ __('Try Fly.io edge for your Node + static sites') }}</p>
                    <p class="mt-1 text-xs text-sky-800">
                        {{ trans_choice('{1} 1 site|[2,*] :count sites', $flyUpsell['eligible_count'], ['count' => $flyUpsell['eligible_count']]) }}
                        {{ __('in this org could deploy close to users in 30+ regions for ~$3/mo each, with sub-100ms response times.') }}
                    </p>
                </div>
                <a href="{{ route('credentials.index', ['provider' => 'fly_io']) }}" wire:navigate class="rounded-xl bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-800">
                    {{ __('Connect Fly.io') }} →
                </a>
            </div>
        </section>
    @endif

    <footer class="mt-8 text-xs text-slate-500">
        {{ __('Same data is available from the terminal:') }}
        <code class="ml-1 select-all rounded bg-slate-100 px-1 py-0.5 font-mono">dply:fleet:doctor</code>
    </footer>
</div>
