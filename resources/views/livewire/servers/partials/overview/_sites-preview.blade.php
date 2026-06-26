{{-- Sites preview. --}}
@if (! $isDedicatedServiceRoleHost && $sitesPreview->isNotEmpty())
    @php
        // Site lifecycle status → human label + tone. The raw values are
        // webserver-specific (nginx_active / caddy_active / container_active …),
        // so collapse them into a handful of states an operator actually reads.
        $siteStatusMeta = function (?string $status): array {
            $s = (string) $status;
            if ($s === '') {
                return ['label' => __('Unknown'), 'tone' => 'neutral'];
            }
            if (str_ends_with($s, '_active') || in_array($s, ['active', 'ready', 'custom_active'], true)) {
                return ['label' => __('Active'), 'tone' => 'emerald'];
            }
            if ($s === 'error' || str_ends_with($s, '_failed')) {
                return ['label' => __('Error'), 'tone' => 'rose'];
            }
            if (in_array($s, ['deploying', 'queued'], true)) {
                return ['label' => __('Deploying'), 'tone' => 'sky'];
            }
            if (str_ends_with($s, '_provisioning') || str_ends_with($s, '_configured') || in_array($s, ['pending', 'scaffolding', 'awaiting_app'], true)) {
                return ['label' => __('Setting up'), 'tone' => 'amber'];
            }

            return ['label' => (string) str($s)->headline(), 'tone' => 'neutral'];
        };
        $toneBadge = [
            'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'rose' => 'border-rose-200 bg-rose-50 text-rose-700',
            'sky' => 'border-sky-200 bg-sky-50 text-sky-700',
            'amber' => 'border-amber-200 bg-amber-50 text-amber-800',
            'neutral' => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
        ];
        $toneDot = [
            'emerald' => 'bg-emerald-500',
            'rose' => 'bg-rose-500',
            'sky' => 'bg-sky-500',
            'amber' => 'bg-amber-500',
            'neutral' => 'bg-brand-mist',
        ];
        $runtimeName = fn (?string $key): string => match ($key) {
            'php' => 'PHP', 'node' => 'Node', 'python' => 'Python',
            'ruby' => 'Ruby', 'go' => 'Go', 'static' => 'Static',
            null => '', default => (string) str($key)->headline(),
        };
    @endphp
    <section class="dply-card overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hosting') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Sites') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Sites hosted on this server, each with its current status and most recent deploy.') }}</p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @if ($siteCount > 0)
                    <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $siteCount }}</span>
                @endif
                @if (($deployableSiteCount ?? 0) > 0)
                    {{-- Deploys the one deployable site immediately, or opens the
                         pick-sites modal when there's more than one (WatchesSiteDeploys). --}}
                    <x-spinner-button
                        variant="primary"
                        size="sm"
                        wire:click="openServerDeploy('{{ $server->id }}')"
                        target="openServerDeploy"
                        icon="heroicon-o-rocket-launch"
                        :label="__('Deploy')"
                        :busy-label="__('Deploying…')"
                    />
                @endif
                <a href="{{ route('servers.sites', $server) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    <x-heroicon-m-rectangle-stack class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Open Sites') }}
                </a>
            </div>
        </div>
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($sitesPreview as $previewSite)
                @php
                    $deploy = $sitesPreviewLatestDeploys[$previewSite->id] ?? null;
                    $deployStatus = $deploy?->status ? (string) $deploy->status : null;
                    $deployTime = $deploy ? ($deploy->finished_at ?? $deploy->started_at ?? $deploy->created_at) : null;
                    $deployTone = match ($deployStatus) {
                        'success' => 'emerald',
                        'failed' => 'rose',
                        'running' => 'sky',
                        'skipped' => 'amber',
                        default => 'neutral',
                    };
                    $deploySha = $deploy?->git_sha ? substr((string) $deploy->git_sha, 0, 7) : null;

                    $status = $siteStatusMeta($previewSite->status);
                    $domain = $previewSite->primaryDomain()?->hostname;
                    $runtimeLabel = (function () use ($previewSite, $runtimeName) {
                        $name = $runtimeName($previewSite->runtimeKey());
                        if ($name === '') {
                            return null;
                        }
                        $version = $previewSite->runtimeVersion();

                        return $version ? $name.' '.$version : $name;
                    })();
                    $sslActive = (string) $previewSite->ssl_status === 'active';
                    $initial = (string) str($previewSite->name)->substr(0, 1)->upper();
                @endphp
                <li wire:key="site-preview-{{ $previewSite->id }}" class="flex items-start justify-between gap-3 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7">
                    <div class="flex min-w-0 flex-1 items-start gap-3">
                        <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-brand-ink/10 bg-brand-sand/40 text-xs font-semibold text-brand-moss">
                            {{ $initial !== '' ? $initial : '•' }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <a href="{{ route('sites.show', ['server' => $server, 'site' => $previewSite]) }}" wire:navigate class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">
                                    {{ $previewSite->name }}
                                </a>
                                <span class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $toneBadge[$status['tone']] }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $toneDot[$status['tone']] }}"></span>
                                    {{ $status['label'] }}
                                </span>
                                @if ($sslActive)
                                    <span class="inline-flex items-center gap-1 text-[10px] font-medium text-emerald-700" title="{{ __('SSL certificate active') }}">
                                        <x-heroicon-m-lock-closed class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        {{ __('SSL') }}
                                    </span>
                                @endif
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-mist">
                                @if ($domain)
                                    <a href="https://{{ $domain }}" target="_blank" rel="noopener noreferrer" class="inline-flex max-w-full items-center gap-1 truncate font-mono text-brand-moss hover:text-brand-sage hover:underline">
                                        <span class="truncate">{{ $domain }}</span>
                                        <x-heroicon-m-arrow-top-right-on-square class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    </a>
                                @endif
                                @if ($runtimeLabel)
                                    @if ($domain)<span class="text-brand-mist/50">·</span>@endif
                                    <span class="font-medium text-brand-moss">{{ $runtimeLabel }}</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-1 text-right">
                        @if ($deployStatus)
                            <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $toneBadge[$deployTone] }}">
                                {{ str($deployStatus)->headline() }}
                            </span>
                        @endif
                        @if ($deployTime)
                            <span class="text-[11px] text-brand-mist">
                                {{ $deployTime->diffForHumans() }}
                                @if ($deploySha)
                                    <span class="text-brand-mist/50"> · </span>
                                    <span class="font-mono text-brand-moss" title="{{ $deploy->git_sha }}">{{ $deploySha }}</span>
                                @endif
                            </span>
                        @else
                            <span class="text-[11px] text-brand-mist">{{ __('No deploys yet') }}</span>
                        @endif
                        @feature('surface.fleet')
                            @if ($deployStatus === 'failed' && ops_copilot_active())
                                <a
                                    href="{{ route('fleet.copilot', ['site' => $previewSite->id]) }}"
                                    wire:navigate
                                    class="mt-0.5 inline-flex shrink-0 items-center gap-1 rounded-lg border border-violet-200 bg-violet-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800 hover:bg-violet-100"
                                >
                                    <x-heroicon-o-sparkles class="h-3 w-3" aria-hidden="true" />
                                    {{ __('Copilot') }}
                                </a>
                            @endif
                        @endfeature
                    </div>
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
