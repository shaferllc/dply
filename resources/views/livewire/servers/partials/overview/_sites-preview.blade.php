{{-- Sites preview. --}}
@if (! $isDedicatedServiceRoleHost && $sitesPreview->isNotEmpty())
    <section class="dply-card overflow-hidden">
        <div class="px-6 pt-5 pb-4 sm:px-7">
            <div class="flex items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Sites') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Sites hosted on this server, each with its current status and most recent deploy.') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if ($siteCount > 0)
                        <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $siteCount }}</span>
                    @endif
                    <a href="{{ route('servers.sites', $server) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        <x-heroicon-m-rectangle-stack class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Open Sites') }}
                    </a>
                </div>
            </div>
        </div>
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($sitesPreview as $previewSite)
                @php
                    $deploy = $sitesPreviewLatestDeploys[$previewSite->id] ?? null;
                    $deployStatus = $deploy?->status ? (string) $deploy->status : null;
                    $deployTime = $deploy ? ($deploy->finished_at ?? $deploy->created_at) : null;
                    $statusBadge = match ($previewSite->status) {
                        'active', 'ready' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                        'deploying', 'queued' => 'border-sky-200 bg-sky-50 text-sky-700',
                        'failed', 'error' => 'border-rose-200 bg-rose-50 text-rose-700',
                        default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
                    };
                @endphp
                <li wire:key="site-preview-{{ $previewSite->id }}" class="flex items-center justify-between gap-3 px-6 py-3 transition-colors hover:bg-brand-sand/15 sm:px-7">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <a href="{{ route('sites.show', ['server' => $server, 'site' => $previewSite]) }}" wire:navigate class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">
                                {{ $previewSite->name }}
                            </a>
                            <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusBadge }}">
                                {{ $previewSite->status ?? '—' }}
                            </span>
                        </div>
                        @if ($deployTime)
                            <p class="mt-0.5 text-[11px] text-brand-mist">
                                {{ __('Last deploy :time', ['time' => $deployTime->diffForHumans()]) }}
                                @if ($deployStatus)
                                    <span class="text-brand-mist/60"> · </span>
                                    <span class="text-brand-moss">{{ str($deployStatus)->headline() }}</span>
                                @endif
                            </p>
                        @else
                            <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('No deploys yet') }}</p>
                        @endif
                    </div>
                    @feature('surface.fleet')
                        @if ($deployStatus === 'failed' && ops_copilot_active())
                            <a
                                href="{{ route('fleet.copilot', ['site' => $previewSite->id]) }}"
                                wire:navigate
                                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-violet-200 bg-violet-50 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-violet-800 hover:bg-violet-100"
                            >
                                <x-heroicon-o-sparkles class="h-3 w-3" aria-hidden="true" />
                                {{ __('Copilot') }}
                            </a>
                        @endif
                    @endfeature
                </li>
            @endforeach
        </ul>
        @if ($siteCount > $sitesPreview->count())
            <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 text-xs text-brand-mist sm:px-7">
                {{ __('Showing :n of :total — open Sites to see the rest.', ['n' => $sitesPreview->count(), 'total' => $siteCount]) }}
            </div>
        @endif
    </section>
@endif
