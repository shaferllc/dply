{{--
    Expandable "related sites" disclosure for a fleet server row/card. Expects
    $server with its `sites` relation eager-loaded — Servers\Index::render()
    does ->with(['sites'])->withCount('sites'), so this adds no queries.
    Pure Alpine toggle.
--}}
@php $disclosureSiteCount = $server->sites_count ?? $server->sites->count(); @endphp
@if ($disclosureSiteCount > 0)
    <div x-data="{ open: false }">
        <button
            type="button"
            @click="open = ! open"
            x-bind:aria-expanded="open"
            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-semibold text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink"
        >
            <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ trans_choice(':count site|:count sites', $disclosureSiteCount, ['count' => $disclosureSiteCount]) }}
            <span class="transition-transform" x-bind:class="{ 'rotate-180': open }">
                <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </span>
        </button>
        <div x-show="open" x-collapse x-cloak class="mt-2">
            <ul class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                @foreach ($server->sites as $site)
                    @php
                        // All derived from the site's own columns — no relation
                        // access, so no extra queries across the fleet. Mirrors
                        // the tone logic on the Sites index.
                        $siteIsProvisioning = $site->isProvisioning();
                        $siteIsFailed = $site->provisioningState() === 'failed'
                            || in_array($site->status, [
                                \App\Models\Site::STATUS_ERROR,
                                \App\Models\Site::STATUS_CONTAINER_FAILED,
                                \App\Models\Site::STATUS_EDGE_FAILED,
                                \App\Models\Site::STATUS_SCAFFOLD_FAILED,
                            ], true);
                        $siteStatusTone = $siteIsFailed
                            ? 'danger'
                            : ($siteIsProvisioning ? 'warning' : ($site->isReadyForTraffic() ? 'success' : 'info'));
                        $siteSslTone = match ($site->ssl_status) {
                            \App\Models\Site::SSL_ACTIVE => 'success',
                            \App\Models\Site::SSL_PENDING => 'warning',
                            \App\Models\Site::SSL_FAILED => 'danger',
                            default => null,
                        };
                        $sitePhp = $site->phpVersion();
                        $siteRuntimeVersion = $site->runtimeVersion();
                        $siteRuntimeChip = $sitePhp
                            ? __('PHP :v', ['v' => $sitePhp])
                            : ($siteRuntimeVersion ? trim(ucfirst((string) ($site->runtimeKey() ?? '')).' '.$siteRuntimeVersion) : null);
                        $siteLastDeploy = $site->last_deploy_at;
                    @endphp
                    <li>
                        <a
                            href="{{ route('sites.show', [$server, $site]) }}"
                            wire:navigate
                            class="block px-3 py-2.5 transition hover:bg-brand-sand/30"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate text-xs font-semibold text-brand-ink">{{ $site->name }}</span>
                                <span class="flex shrink-0 items-center gap-1">
                                    <x-badge size="sm" :tone="$siteStatusTone">{{ $site->statusLabel() }}</x-badge>
                                    @if ($siteSslTone !== null)
                                        <x-badge size="sm" :tone="$siteSslTone">{{ __('SSL') }}</x-badge>
                                    @endif
                                </span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-moss">
                                @if ($site->type)
                                    <span class="inline-flex items-center gap-1">
                                        <x-heroicon-o-cpu-chip class="h-3 w-3 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ $site->type->label() }}
                                    </span>
                                    <span class="text-brand-mist">·</span>
                                @endif
                                @if ($siteRuntimeChip)
                                    <span>{{ $siteRuntimeChip }}</span>
                                    <span class="text-brand-mist">·</span>
                                @endif
                                @if ($siteLastDeploy)
                                    <span class="inline-flex items-center gap-1" title="{{ $siteLastDeploy }}">
                                        <x-heroicon-o-rocket-launch class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                        {{ __('Deployed :ago', ['ago' => $siteLastDeploy->diffForHumans()]) }}
                                    </span>
                                @else
                                    <span>{{ __('Not deployed yet') }}</span>
                                @endif
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
