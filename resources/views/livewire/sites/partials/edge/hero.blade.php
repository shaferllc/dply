<section class="dply-card overflow-hidden">
    <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/20 dark:text-brand-sage">
                <x-heroicon-o-globe-alt class="h-6 w-6" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ $site->name }}</h2>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 {{ $edgeStatusBadgeClass }}">
                        {{ $edgeStatusLabel }}
                    </span>
                </div>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('Edge delivery') }}
                    @if ($edgeSourceSpec)
                        <span class="text-brand-mist/70">·</span>
                        <span class="font-mono text-xs text-brand-ink">{{ $edgeSourceRef }}</span>
                    @endif
                </p>
                @if ($edgeLiveUrl && ! empty($edgeActiveDeploymentId))
                    <div class="mt-3 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                        <a href="{{ $edgeLiveUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-brand-forest/25 bg-brand-forest/8 px-3 py-1 font-mono text-[11px] text-brand-forest hover:bg-brand-forest/15 dark:border-brand-sage/30 dark:bg-brand-sage/10 dark:text-brand-sage">
                            <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0" />
                            <span class="truncate">{{ $edgeLiveUrl }}</span>
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0 opacity-70" />
                        </a>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900 dark:text-brand-sage"
                            @click="navigator.clipboard.writeText(@js($edgeLiveUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                        >
                            <x-heroicon-o-clipboard class="h-3.5 w-3.5" />
                            <span x-show="!copied">{{ __('Copy URL') }}</span>
                            <span x-show="copied" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                        </button>
                    </div>
                @elseif ($site->status === \App\Models\Site::STATUS_EDGE_FAILED && empty($edgeActiveDeploymentId))
                    <p class="mt-2 text-sm text-rose-700">{{ __('First deploy did not publish. Once a build succeeds, the live URL appears here.') }}</p>
                @else
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Live URL pending — complete the first build to publish.') }}</p>
                @endif
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @if ($edgeLiveUrl && ! empty($edgeActiveDeploymentId))
                <a href="{{ $edgeLiveUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900">
                    <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" />
                    {{ __('Open live site') }}
                </a>
            @endif
            @can('update', $site)
                <button
                    type="button"
                    wire:click="redeployEdge"
                    wire:loading.attr="disabled"
                    wire:target="redeployEdge"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 hover:bg-brand-forest/90 disabled:opacity-60"
                >
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="redeployEdge" />
                    <span wire:loading wire:target="redeployEdge" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="white" size="sm" /></span>
                    <span wire:loading.remove wire:target="redeployEdge">{{ __('Redeploy') }}</span>
                    <span wire:loading wire:target="redeployEdge">{{ __('Queueing…') }}</span>
                </button>
            @endcan
        </div>
    </div>

    <div class="grid gap-4 px-6 py-4 text-xs sm:grid-cols-2 lg:grid-cols-4 sm:px-8">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Build command') }}</p>
            <p class="mt-1 font-mono text-[11px] text-brand-ink break-all">{{ $edgeBuildCommand }}</p>
        </div>
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Output directory') }}</p>
            <p class="mt-1 font-mono text-[11px] text-brand-ink">{{ $edgeOutputDir }}</p>
        </div>
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Latest deploy') }}</p>
            @if ($edgeLatestDeployment)
                <p class="mt-1 font-medium capitalize text-brand-ink">{{ str_replace('_', ' ', (string) $edgeLatestDeployment->status) }}</p>
                <p class="text-brand-moss">{{ optional($edgeLatestDeployment->published_at ?? $edgeLatestDeployment->created_at)->diffForHumans() ?? '—' }}</p>
            @else
                <p class="mt-1 text-brand-moss">{{ __('No deploys yet') }}</p>
            @endif
        </div>
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Auto deploy') }}</p>
            <p class="mt-1 font-medium text-brand-ink">{{ $edgeDeployOnPush ? __('On push to :branch', ['branch' => $edgeBranch]) : __('Manual only') }}</p>
        </div>
    </div>
</section>

{{-- "Last error" callout removed — the delivery banner at the top of
     the workspace already surfaces the failure reason in a more visible
     spot, so showing both made the same message appear twice on the
     overview. The banner copy is computed in EdgeSiteViewData::deliveryBanner. --}}
