                @if ($site->workspace)
                    @feature('surface.projects')
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
                            <p class="font-medium text-brand-ink">{{ __('Project context') }}</p>
                            <p class="mt-1">
                                {{ __('Rolls up into the :project project.', ['project' => $site->workspace->name]) }}
                                <a href="{{ route('projects.operations', $site->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ __('Operations') }}</a>
                                ·
                                <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ __('Delivery') }}</a>
                            </p>
                        </div>
                    @endfeature
                @endif

                {{-- Section order is by operational importance: Health (is the site
                     up?) and Preflight (anything blocking a deploy?) lead, then the
                     reference cards — Endpoints (where it lives) and Resources (what
                     it depends on). --}}
                <div class="grid gap-6 lg:grid-cols-2">
                    {{-- Health --}}
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <x-icon-badge>
                                <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Health') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Health & checks') }}</h3>
                            </div>
                            <a href="{{ route('sites.monitor', [$server, $site]) }}" wire:navigate class="ml-auto shrink-0 self-center text-xs font-medium text-brand-sage hover:underline">{{ __('Open monitor') }}</a>
                        </div>
                        <ul class="divide-y divide-brand-ink/8 px-6 sm:px-8">
                            <li class="flex items-start justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-brand-ink">{{ __('URL responds') }}</p>
                                    <p class="text-xs text-brand-moss">{{ __('Last checked') }} {{ $healthLastCheck ? \Illuminate\Support\Carbon::parse($healthLastCheck)->diffForHumans() : __('never') }}</p>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide
                                    {{ $healthLastOk === true ? 'bg-emerald-100 text-emerald-800' : ($healthLastOk === false ? 'bg-red-100 text-red-800' : 'bg-brand-sand/40 text-brand-mist') }}">
                                    {{ $healthLastOk === true ? __('OK') : ($healthLastOk === false ? __('Failed') : __('—')) }}
                                </span>
                            </li>
                            <li class="flex items-start justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-brand-ink">{{ __('Runtime contract') }}</p>
                                    <p class="break-all font-mono text-[11px] text-brand-mist">{{ \Illuminate\Support\Str::limit((string) ($foundationStatus['current_runtime_revision'] ?? '—'), 24) }}</p>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide
                                    {{ $runtimeDrifted ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                                    {{ $runtimeDrifted ? __('Drift') : __('In sync') }}
                                </span>
                            </li>
                            @php $cloudflareTls = $site->cloudflareTerminatesTls(); @endphp
                            <li class="flex items-start justify-between gap-3 py-3"
                                @if ($ssl_recheck_running) wire:poll.3s="pollSslRecheck" @endif>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-brand-ink">{{ __('SSL') }}</p>
                                    @if ($cloudflareTls)
                                        <p class="text-xs text-brand-moss">{{ __('Active — secured via Cloudflare') }}</p>
                                    @else
                                        <p class="text-xs capitalize text-brand-moss">{{ $site->ssl_status ?: __('Not configured') }}</p>
                                    @endif
                                    <button type="button" wire:click="recheckSsl" wire:loading.attr="disabled" wire:target="recheckSsl"
                                        @if ($ssl_recheck_running) disabled @endif
                                        class="mt-1 text-[11px] font-semibold text-brand-forest hover:underline disabled:opacity-50">
                                        {{ $ssl_recheck_running ? __('Rechecking…') : __('Recheck SSL') }}
                                    </button>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide
                                    {{ $cloudflareTls ? 'bg-emerald-100 text-emerald-800' : 'bg-brand-sand/40 text-brand-moss' }}">
                                    {{ $cloudflareTls ? __('Cloudflare') : ($site->currentSslSummary() ?: '—') }}
                                </span>
                            </li>
                            @if ($site->isSuspended())
                                <li class="flex items-start justify-between gap-3 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-brand-ink">{{ __('Public traffic') }}</p>
                                        <p class="text-xs text-amber-800">{{ __('Suspended — visitors see the suspended page.') }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">{{ __('Suspended') }}</span>
                                </li>
                            @endif
                            @if ($hostChecks->isNotEmpty())
                                <li class="py-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Reachability checks') }}</p>
                                    <ul class="mt-2 space-y-1.5">
                                        @foreach ($hostChecks as $check)
                                            <li class="flex items-center justify-between gap-3 rounded-lg border {{ ($check['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50/60' : 'border-amber-200 bg-amber-50/60' }} px-3 py-2">
                                                <p class="break-all font-mono text-[11px] text-brand-ink">{{ $check['hostname'] }}</p>
                                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ ($check['ok'] ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                                    {{ ($check['ok'] ?? false) ? __('Ready') : __('Waiting') }}
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </li>
                            @endif
                        </ul>
                    </section>

                    {{-- Preflight (only once a deploy has been attempted) --}}
                    @if ($preflightActive ?? false)
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <x-icon-badge>
                                <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Preflight') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Launch preflight') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Shared deployment checks for config, publication, and attached resources.') }}</p>
                            </div>
                        </div>
                        <div class="px-6 py-6 sm:px-7">
                        @if ($preflightErrors->isEmpty() && $preflightWarnings->isEmpty())
                            <p class="text-sm font-medium text-emerald-700">{{ __('No blocking preflight issues.') }}</p>
                        @else
                            <x-site-preflight-issues-panel :checks="$preflightActionableChecks" compact />
                        @endif
                        </div>
                    </section>
                    @endif
                </div>

                {{-- Reference: where it lives + what it depends on --}}
                <div class="grid gap-6 lg:grid-cols-2">
                    {{-- Endpoints --}}
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <x-icon-badge>
                                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Routing') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Endpoints') }}</h3>
                            </div>
                            <a href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'routing']) }}" wire:navigate class="ml-auto shrink-0 self-center text-xs font-medium text-brand-sage hover:underline">{{ __('Manage routing') }}</a>
                        </div>
                        <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Primary domain') }}</dt>
                                <dd class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">{{ $primaryHostname ?? '—' }}</dd>
                            </div>
                            @if ($aliasHostnames->isNotEmpty())
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Aliases') }}</dt>
                                    <dd class="min-w-0 flex-1 space-y-0.5 font-mono text-xs text-brand-ink">
                                        @foreach ($aliasHostnames as $alias)
                                            <p class="break-all">{{ $alias }}</p>
                                        @endforeach
                                    </dd>
                                </div>
                            @endif
                            @if ($previewDomain?->hostname)
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Preview') }}</dt>
                                    <dd class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">
                                        {{ $previewDomain->hostname }}
                                        <span class="text-brand-mist">· {{ $previewDomain->dns_status ?? __('not configured') }}</span>
                                    </dd>
                                </div>
                            @endif
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Testing URL') }}</dt>
                                <dd class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">
                                    @if ($testingHostname !== '')
                                        {{ $testingHostname }}
                                        @if (! $site->isReadyForTraffic())
                                            <span class="text-brand-mist">· {{ __('still polling') }}</span>
                                        @endif
                                    @elseif (($testingHostnameMeta['status'] ?? null) === 'failed')
                                        <span class="text-amber-800">{{ $testingHostnameMeta['error'] ?? __('failed to assign') }}</span>
                                    @else
                                        <span class="text-brand-mist">{{ __('none assigned') }}</span>
                                    @endif
                                </dd>
                            </div>
                            @if ($site->internal_port)
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Internal port') }}</dt>
                                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">127.0.0.1:{{ $site->internal_port }}</dd>
                                </div>
                            @endif
                            @if ($site->usesDockerRuntime() && ($runtimePublication['url'] ?? null))
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-32 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Published URL') }}</dt>
                                    <dd class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">{{ $runtimePublication['url'] }}</dd>
                                </div>
                            @endif
                        </dl>
                        <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 text-xs text-brand-moss sm:px-8">
                            {{ __('Show this site on a public') }}
                            <a href="{{ route('status-pages.index') }}" class="font-medium text-brand-ink hover:underline">{{ __('status page') }}</a>.
                        </div>
                    </section>

                    {{-- Resources --}}
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <x-icon-badge>
                                <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Resources') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Attached resources') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('What this site expects around the app runtime.') }}</p>
                            </div>
                        </div>
                        <div class="px-6 py-6 sm:px-7">
                        @if ($resourceBindings->isEmpty())
                            <p class="text-sm text-brand-mist">{{ __('No resource bindings recorded.') }}</p>
                        @else
                            <div class="space-y-2">
                                @foreach ($resourceBindings as $binding)
                                    @include('livewire.sites.partials.resource-binding-row', [
                                        'binding' => $binding,
                                        'configuredClass' => 'bg-emerald-100 text-emerald-700',
                                    ])
                                @endforeach
                            </div>
                        @endif

                        {{-- Attached worker SERVER pools (the scalable background fleet on this
                             site's private network). Shows only when one is attached; manage on
                             Resources, scale on the pool page. See Site::attachedWorkerPools(). --}}
                        @php $workerPools = $site->attachedWorkerPools(); @endphp
                        @if ($workerPools->isNotEmpty())
                            <div class="mt-5 border-t border-brand-ink/10 pt-5">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Worker servers') }}</p>
                                    <a href="{{ route('sites.resources', [$site->server, $site]) }}" wire:navigate class="text-[11px] font-semibold text-brand-forest hover:underline">{{ __('Manage') }} →</a>
                                </div>
                                <ul class="mt-2 space-y-2">
                                    @foreach ($workerPools as $pool)
                                        @php $primary = $pool->primaryServer; @endphp
                                        <li class="rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <x-heroicon-o-square-3-stack-3d class="h-4 w-4 shrink-0 text-violet-700" aria-hidden="true" />
                                                    <span class="text-sm font-semibold text-brand-ink">{{ $pool->name ?: __('Worker pool') }}</span>
                                                    <span class="rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800">{{ trans_choice(':n server|:n servers', $pool->servers->count(), ['n' => $pool->servers->count()]) }}</span>
                                                    <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-mono text-slate-700">{{ $pool->status }}</span>
                                                </div>
                                                @if ($primary)
                                                    <a href="{{ route('servers.worker-pool', ['server' => $primary]) }}" wire:navigate class="text-[11px] font-semibold text-brand-forest hover:underline">{{ __('Scale') }} →</a>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        </div>
                    </section>
                </div>
