            @php
                $serverWebserver = (string) ($server->meta['webserver'] ?? '');
                $headlessHostNoCaddy = $serverWebserver === 'none' && ! $site->usesEdgeRuntime();
                $caddyInstallPending = (bool) ($server->meta['webserver_install_pending'] ?? false);
            @endphp

            @if ($headlessHostNoCaddy)
                <section class="mb-4 rounded-2xl border border-amber-300 bg-amber-50/80 p-5 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-800 ring-1 ring-amber-200">
                                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-amber-900">{{ __('No web server on this host') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-amber-900/90">
                                    {{ __('This server was provisioned without a web server, so there\'s no testing URL and no vhost. Install Caddy now and Dply will re-provision the sites on this server to attach testing hostnames.') }}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            wire:click="installServerWebserver"
                            wire:loading.attr="disabled"
                            wire:target="installServerWebserver"
                            @disabled($caddyInstallPending)
                            class="inline-flex shrink-0 items-center justify-center gap-2 self-start rounded-xl bg-amber-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-800 disabled:cursor-progress disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="installServerWebserver" class="inline-flex items-center gap-2">
                                <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                                {{ $caddyInstallPending ? __('Installing Caddy…') : __('Install Caddy') }}
                            </span>
                            <span wire:loading wire:target="installServerWebserver" class="inline-flex items-center gap-2">
                                <x-spinner size="sm" variant="white" />
                                {{ __('Queuing…') }}
                            </span>
                        </button>
                    </div>
                </section>
            @endif

            <x-explainer class="mb-2">
                <p>{{ __('Site dashboard — health at a glance, the most-recent endpoints, and the deploy controls.') }}</p>
                <p>{{ __('Routing, certificates, environment, redirects, deploy hooks, and destructive actions live in') }}
                    <a href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate>{{ __('Site settings') }}</a>.
                    {{ __('Logs, runtime, and SSL are tabs below. Webserver config and Insights have dedicated pages linked above.') }}
                </p>
            </x-explainer>

            {{-- Hero card: identity + endpoints + primary action ----------------------------------- --}}
            <section class="dply-card overflow-hidden">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                            <x-heroicon-o-globe-alt class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-brand-ink">{{ $primaryHostname ?? $site->name }}</h2>
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 {{ $toneClasses }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $toneDot }}"></span>
                                    {{ $statusLabel }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                <span class="capitalize">{{ $site->type->label() }}</span>
                                @if ($site->runtimeKey())
                                    <span class="text-brand-mist/70">·</span>
                                    <span class="capitalize">{{ $site->runtimeKey() }}</span>@if ($site->runtimeVersion())<span class="font-mono text-brand-mist"> {{ $site->runtimeVersion() }}</span>@endif
                                @endif
                                <span class="text-brand-mist/70">·</span>
                                <span class="capitalize">{{ $site->webserver() }}</span>
                                <span class="text-brand-mist/70">·</span>
                                <span>{{ $site->deploy_strategy === 'atomic' ? __('atomic deploys') : __('simple deploys') }}</span>
                            </p>
                            <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-xs">
                                @if ($site->visitUrl())
                                    <a href="{{ $site->visitUrl() }}" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1 rounded-full border border-brand-forest/25 bg-brand-forest/8 px-2.5 py-1 font-mono text-[11px] text-brand-forest hover:bg-brand-forest/15">
                                        <x-heroicon-m-globe-alt class="h-3 w-3" />
                                        <span class="truncate">{{ $site->visitUrl() }}</span>
                                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 shrink-0 opacity-70" />
                                    </a>
                                @endif
                                @foreach ($aliasHostnames as $alias)
                                    <span class="inline-flex max-w-full items-center gap-1 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 font-mono text-[11px] text-brand-ink">
                                        <x-heroicon-m-link class="h-3 w-3 text-brand-mist" />
                                        <span class="truncate">{{ $alias }}</span>
                                    </span>
                                @endforeach
                                @if ($testingHostname !== '')
                                    <a href="http://{{ $testingHostname }}" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 font-mono text-[11px] text-sky-900 hover:bg-sky-100">
                                        <x-heroicon-m-beaker class="h-3 w-3" />
                                        <span class="truncate">{{ $testingHostname }}</span>
                                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 shrink-0 opacity-70" />
                                    </a>
                                @endif
                                @if ($previewDomain?->hostname)
                                    <a href="http://{{ $previewDomain->hostname }}" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 font-mono text-[11px] text-brand-ink hover:bg-brand-sand/40">
                                        <x-heroicon-m-eye class="h-3 w-3 text-brand-mist" />
                                        <span class="truncate">{{ $previewDomain->hostname }}</span>
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="deployNow"
                            wire:loading.attr="disabled"
                            wire:target="deployNow"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" wire:loading.remove wire:target="deployNow" />
                            <span wire:loading wire:target="deployNow" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="white" size="sm" /></span>
                            <span wire:loading.remove wire:target="deployNow">{{ __('Deploy now') }}</span>
                            <span wire:loading wire:target="deployNow">{{ __('Deploying…') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="queueDeploy"
                            wire:loading.attr="disabled"
                            wire:target="queueDeploy"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-o-queue-list class="h-3.5 w-3.5" />
                            {{ __('Queue deploy') }}
                        </button>
                        <span class="hidden h-5 w-px bg-brand-ink/10 sm:block" aria-hidden="true"></span>
                        <a href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => $site->usesDockerRuntime() ? 'runtime' : 'general']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5" />
                            {{ __('Settings') }}
                        </a>
                    </div>
                </div>

                <div class="grid gap-4 px-6 py-4 text-xs sm:grid-cols-3 sm:gap-6 sm:px-8">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Last deploy') }}</p>
                        @if ($latestDeployment)
                            <p class="mt-1 font-medium text-brand-ink">
                                <span class="capitalize">{{ str_replace('_', ' ', (string) $latestDeployment->status) }}</span>
                                <span class="text-brand-mist/80">·</span>
                                <span class="text-brand-moss">{{ optional($latestDeployment->started_at ?? $latestDeployment->created_at)->diffForHumans() ?? '—' }}</span>
                            </p>
                            @if ($latestDeployment->git_sha)
                                <p class="mt-0.5 font-mono text-[11px] text-brand-mist">{{ \Illuminate\Support\Str::limit($latestDeployment->git_sha, 14, '') }}</p>
                            @endif
                        @else
                            <p class="mt-1 text-brand-moss">{{ __('No deploys yet.') }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('URL health') }}</p>
                        @if ($healthLastCheck)
                            <p class="mt-1 font-medium {{ $healthLastOk ? 'text-emerald-700' : 'text-red-700' }}">
                                {{ $healthLastOk ? __('OK') : __('Failed') }}
                                <span class="font-normal text-brand-mist/80">·</span>
                                <span class="font-normal text-brand-moss">{{ \Illuminate\Support\Carbon::parse($healthLastCheck)->diffForHumans() }}</span>
                            </p>
                        @else
                            <p class="mt-1 text-brand-moss">{{ __('Not checked yet') }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('SSL') }}</p>
                        <p class="mt-1 font-medium capitalize text-brand-ink">{{ $site->currentSslSummary() ?: __('—') }}</p>
                    </div>
                </div>
            </section>

            {{-- Tablist ---------------------------------------------------------------------------- --}}
            <x-server-workspace-tablist :aria-label="__('Site dashboard sections')" class="mt-6">
                <x-server-workspace-tab id="site-tab-overview" :active="$activeTab === 'overview'" wire:click="$set('dashboard_tab', 'overview')">
                    <span class="inline-flex items-center gap-1.5"><x-heroicon-o-rectangle-stack class="h-4 w-4" />{{ __('Overview') }}</span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="site-tab-deploys" :active="$activeTab === 'deploys'" wire:click="$set('dashboard_tab', 'deploys')">
                    <span class="inline-flex items-center gap-1.5"><x-heroicon-o-code-bracket class="h-4 w-4" />{{ __('Deploys') }}</span>
                </x-server-workspace-tab>
                @if ($showRuntimeTab)
                    <x-server-workspace-tab id="site-tab-runtime" :active="$activeTab === 'runtime'" wire:click="$set('dashboard_tab', 'runtime')">
                        <span class="inline-flex items-center gap-1.5"><x-heroicon-o-cube class="h-4 w-4" />{{ __('Runtime') }}</span>
                    </x-server-workspace-tab>
                @endif
                <x-server-workspace-tab id="site-tab-logs" :active="$activeTab === 'logs'" wire:click="$set('dashboard_tab', 'logs')">
                    <span class="inline-flex items-center gap-1.5"><x-heroicon-o-clipboard-document-list class="h-4 w-4" />{{ __('Logs') }}</span>
                </x-server-workspace-tab>
                @if ($showSslTab)
                    <x-server-workspace-tab id="site-tab-ssl" :active="$activeTab === 'ssl'" wire:click="$set('dashboard_tab', 'ssl')">
                        <span class="inline-flex items-center gap-1.5"><x-heroicon-o-lock-closed class="h-4 w-4" />{{ __('SSL') }}</span>
                    </x-server-workspace-tab>
                @endif
            </x-server-workspace-tablist>
