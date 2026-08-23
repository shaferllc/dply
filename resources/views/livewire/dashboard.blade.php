@php
    $user = auth()->user();
    $displayName = filled($user->name ?? null) ? $user->name : __('there');
    $organizationName = $organization?->name ?? __('Your organization');
    $openFindings = (int) ($orgInsights['total_open'] ?? 0);
    $avgHealthScore = $orgInsights['avg_health_score'] ?? null;

    $primaryHref = route('servers.create');
    $primaryLabel = __('Add a server');
    $shellDescription = __('Every server in :organization, worst first.', ['organization' => $organizationName]);
    // Sized for the dense panel head so every control on the page is one size.
    $headerBtn = 'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm transition-colors';
    $chipBase = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors';
    $chipOn = $chipBase.' border-brand-ink bg-brand-ink text-brand-cream';
    $chipOff = $chipBase.' border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40';
@endphp

<div class="contents">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <x-profile-shell
            dense
            :title="__('Welcome back, :name', ['name' => $displayName])"
            :description="$shellDescription"
            icon="heroicon-o-squares-2x2"
        >
            <x-slot:actions>
                <a
                    href="{{ route('credentials.index') }}"
                    wire:navigate
                    class="{{ $headerBtn }} border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Credentials') }}
                </a>
                <button
                    type="button"
                    class="{{ $headerBtn }} border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40"
                    x-on:click="window.dispatchEvent(new CustomEvent('dply-docs-open', { detail: { docRoute: 'docs.connect-provider' } }))"
                >
                    <x-heroicon-o-document-text class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Setup guide') }}
                </button>
                <a
                    href="{{ $primaryHref }}"
                    wire:navigate
                    class="{{ $headerBtn }} bg-brand-ink text-brand-cream shadow-md hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ $primaryLabel }}
                </a>
            </x-slot:actions>

            @if ($healthAlert !== null)
                <div class="border-b border-brand-ink/10 bg-rose-50/80 px-3 py-2 sm:px-4" role="alert">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-2">
                            <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
                            <div class="min-w-0 sm:flex sm:flex-wrap sm:items-center sm:gap-x-2">
                                <h2 class="text-sm font-semibold text-rose-900">{{ __('Infrastructure needs attention') }}</h2>
                                <span class="hidden h-4 w-px shrink-0 bg-rose-900/15 sm:block" aria-hidden="true"></span>
                                <p class="text-xs leading-relaxed text-rose-800">
                                    @if ($healthAlert['failed_latest'] > 0)
                                        {{ trans_choice('{1} 1 site with a failed latest deploy.|[2,*] :count sites with a failed latest deploy.', $healthAlert['failed_latest'], ['count' => $healthAlert['failed_latest']]) }}
                                    @endif
                                    @if ($healthAlert['long_running'] > 0)
                                        {{ trans_choice('{1} 1 deploy running over 15 minutes.|[2,*] :count deploys running over 15 minutes.', $healthAlert['long_running'], ['count' => $healthAlert['long_running']]) }}
                                    @endif
                                    @if ($healthAlert['drift_servers'] > 0)
                                        {{ trans_choice('{1} 1 server with engine drift.|[2,*] :count servers with engine drift.', $healthAlert['drift_servers'], ['count' => $healthAlert['drift_servers']]) }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 self-start whitespace-nowrap rounded-lg bg-rose-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-800 sm:self-auto">
                            {{ __('View infrastructure health') }}
                            <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        </a>
                    </div>
                </div>
            @endif

            @unless ($hasProviderCredentials)
                <div class="border-b border-brand-ink/10 bg-amber-50/60 px-3 py-2 sm:px-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-2">
                            <x-heroicon-o-shield-exclamation class="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                            <div class="min-w-0 sm:flex sm:flex-wrap sm:items-center sm:gap-x-2">
                                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Add provider credentials before you provision') }}</h2>
                                <span class="hidden h-4 w-px shrink-0 bg-amber-900/15 sm:block" aria-hidden="true"></span>
                                <p class="text-xs leading-relaxed text-brand-moss">{{ __('Connect a supported infrastructure provider so this workspace can launch and manage real servers instead of stopping at setup.') }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-1.5 sm:items-center">
                            <a
                                href="{{ route('credentials.index') }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                            >
                                <x-heroicon-m-key class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Connect provider') }}
                            </a>
                            <a
                                href="{{ route('docs.connect-provider') }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-m-document-text class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Setup guide') }}
                            </a>
                        </div>
                    </div>
                </div>
            @endunless

            @if ($serverCount === 0)
                {{-- Nothing to tabulate yet: the page becomes the one thing to do. --}}
                <div class="flex flex-col items-center justify-center px-3 py-12 text-center sm:px-4">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-server-stack class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No servers yet') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
{{-- multi_surface_active() used to gate a launchpad variant here; the
                             helper does not exist anywhere in the app, so the zero-server
                             dashboard fatalled on it. --}}
                        {{ __('Spin up your first server — bring your own host or provision a VM from a connected cloud provider.') }}
                    </p>
                    <a
                        href="{{ $primaryHref }}"
                        wire:navigate
                        class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ $primaryLabel }}
                    </a>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
                    <label class="relative min-w-[12rem] flex-1">
                        <span class="sr-only">{{ __('Filter servers') }}</span>
                        <x-heroicon-m-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                        <input
                            type="search"
                            wire:model.live.debounce.300ms="q"
                            placeholder="{{ __('Filter by name or IP…') }}"
                            class="w-full rounded-lg border border-brand-ink/15 bg-white py-1.5 pl-8 pr-3 text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        />
                    </label>

                    <button type="button" wire:click="$set('filter', 'all')" class="{{ $filter === 'all' ? $chipOn : $chipOff }}">
                        {{ __('All') }}
                        <span class="font-mono tabular-nums opacity-70">{{ $matchedCount }}</span>
                    </button>
                    <button type="button" wire:click="$set('filter', 'attention')" class="{{ $filter === 'attention' ? $chipOn : $chipOff }}">
                        {{ __('Needs attention') }}
                        <span @class([
                            'font-mono tabular-nums',
                            'text-rose-600' => $attentionCount > 0 && $filter !== 'attention',
                            'opacity-70' => $attentionCount === 0 || $filter === 'attention',
                        ])>{{ $attentionCount }}</span>
                    </button>

                    <div wire:loading.delay wire:target="q,filter" class="text-2xs uppercase tracking-wide text-brand-mist">{{ __('Filtering…') }}</div>
                </div>

                @if ($rows->isEmpty())
                    <div class="px-3 py-10 text-center sm:px-4">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Nothing matches') }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('No server matches this filter. Clear it to see the whole fleet.') }}</p>
                        <button type="button" wire:click="clearFilters" class="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            {{ __('Clear filters') }}
                        </button>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[46rem] border-collapse text-sm">
                            <thead>
                                <tr class="border-b border-brand-ink/10">
                                    <th scope="col" class="px-3 pb-2 pt-1 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist sm:px-4">{{ __('Server') }}</th>
                                    <th scope="col" class="px-3 pb-2 pt-1 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Provider') }}</th>
                                    <th scope="col" class="px-3 pb-2 pt-1 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Health') }}</th>
                                    <th scope="col" class="px-3 pb-2 pt-1 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Findings') }}</th>
                                    <th scope="col" class="px-3 pb-2 pt-1 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last deploy') }}</th>
                                    <th scope="col" class="px-3 pb-2 pt-1 text-right"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10">
                                @foreach ($rows as $row)
                                    @php
                                        $server = $row['server'];
                                        $health = $row['health'];
                                        $worst = $row['worst'];
                                        $deployStatus = $row['deploy_status'];

                                        // Dot = the row's headline state. Findings and a failed
                                        // deploy both feed it so one glance down the column reads
                                        // as the triage order the rows are already sorted in.
                                        $dotTone = match (true) {
                                            $deployStatus === 'failed', $worst === 'critical' => 'bg-rose-500',
                                            $worst === 'warning' => 'bg-amber-500',
                                            $worst === 'info' => 'bg-sky-500',
                                            default => 'bg-brand-sage',
                                        };
                                        $healthTone = match (true) {
                                            $health === null => 'bg-brand-ink/15',
                                            $health >= 80 => 'bg-brand-sage',
                                            $health >= 50 => 'bg-amber-500',
                                            default => 'bg-rose-500',
                                        };
                                        $findingTone = match ($worst) {
                                            'critical' => 'border-rose-200 bg-rose-50 text-rose-700',
                                            'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
                                            'info' => 'border-sky-200 bg-sky-50 text-sky-700',
                                            default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
                                        };
                                        $deployTone = match ($deployStatus) {
                                            'failed' => 'text-rose-700',
                                            'running' => 'text-sky-700',
                                            'success' => 'text-brand-moss',
                                            null => 'text-brand-mist',
                                            default => 'text-brand-moss',
                                        };
                                    @endphp
                                    <tr wire:key="fleet-{{ $server->id }}" class="transition-colors hover:bg-brand-sand/15">
                                        <td class="px-3 py-2.5 align-middle sm:px-4">
                                            <div class="flex items-center gap-2">
                                                <span class="h-2 w-2 shrink-0 rounded-full {{ $dotTone }}" aria-hidden="true"></span>
                                                <div class="min-w-0">
                                                    <a href="{{ route('servers.show', $server) }}" wire:navigate class="block truncate text-sm font-semibold text-brand-ink hover:text-brand-forest">
                                                        {{ $server->name }}
                                                    </a>
                                                    <p class="mt-0.5 truncate font-mono text-2xs text-brand-mist">
                                                        @if ($server->ip_address)
                                                            {{ $server->ip_address }}
                                                            <span aria-hidden="true" class="text-brand-mist/50">·</span>
                                                        @endif
                                                        {{ $server->sites_count }} {{ trans_choice('site|sites', $server->sites_count) }}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2.5 align-middle">
                                            <p class="truncate text-xs text-brand-moss">{{ $server->providerDisplayLabel() }}</p>
                                            <p class="mt-0.5 truncate font-mono text-2xs text-brand-mist">
                                                {{ $server->region ?: '—' }}@if ($server->size) <span aria-hidden="true" class="text-brand-mist/50">·</span> {{ $server->size }}@endif
                                            </p>
                                        </td>
                                        <td class="px-3 py-2.5 align-middle">
                                            <div class="h-1.5 w-16 overflow-hidden rounded-full bg-brand-ink/10">
                                                <div class="h-full rounded-full {{ $healthTone }}" style="width: {{ $health ?? 0 }}%"></div>
                                            </div>
                                            <p class="mt-1 font-mono text-2xs tabular-nums text-brand-moss">{{ $health ?? '—' }}</p>
                                        </td>
                                        <td class="px-3 py-2.5 align-middle">
                                            @if ($row['open'] > 0)
                                                <a href="{{ route('servers.insights', $server) }}" wire:navigate class="inline-flex items-center rounded-md border px-1.5 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide {{ $findingTone }}">
                                                    {{ $row['open'] }} {{ $worst ?? __('open') }}
                                                </a>
                                            @else
                                                <span class="text-xs text-brand-mist">{{ __('clean') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 align-middle">
                                            @if ($deployStatus === null)
                                                <span class="font-mono text-2xs text-brand-mist">—</span>
                                            @else
                                                <p class="text-xs font-medium {{ $deployTone }}">{{ str_replace('_', ' ', $deployStatus) }}</p>
                                                @if ($row['deploy_at'])
                                                    <p class="mt-0.5 font-mono text-2xs text-brand-mist">{{ $row['deploy_at']->diffForHumans(short: true) }}</p>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right align-middle sm:px-4">
                                            <a href="{{ route('servers.show', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                                {{ __('Manage') }}
                                                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2 text-xs text-brand-moss sm:px-4">
                        <span>
                            {{ __(':shown of :total shown', ['shown' => $rows->count(), 'total' => $serverCount]) }}
                            <span aria-hidden="true" class="text-brand-mist/60">·</span>
                            {{ __('worst first') }}
                        </span>
                        <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex items-center gap-1 font-mono tabular-nums hover:text-brand-ink">
                            {{-- Workspace-wide on purpose: this rollup spans every host and
                                 every site-scoped finding, so it will not tally to the
                                 server-scoped counts in the column above. --}}
                            {{ __(':count open findings across the workspace', ['count' => $openFindings]) }}@if ($avgHealthScore !== null) <span aria-hidden="true" class="text-brand-mist/60">·</span> {{ __('health :score', ['score' => (int) $avgHealthScore]) }}@endif
                            <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                        </a>
                    </div>
                @endif
            @endif
        </x-profile-shell>
    </div>
</div>
