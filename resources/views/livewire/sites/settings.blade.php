@php
    $productionMirrorSite = data_get($site->meta, 'production_data_mirror') === true;
    $productionConnection = $productionMirrorSite && production_data_mirror_connected()
        ? app(\App\Services\ProductionData\ProductionDataMirror::class)->connectionFor(auth()->user())
        : null;
    $mergedChromeSections = ['general', 'settings', 'cli', 'routing', 'certificates', 'repository', 'runtime', 'resources', 'system-user', 'laravel-stack', 'logs', 'notifications', 'basic-auth', 'danger'];
    $usesMergedChrome = in_array($section, $mergedChromeSections, true);
@endphp
<div>
    @if ($productionConnection)
        <x-production-data-banner
            :connection="$productionConnection"
            :writes-unlocked="app(\App\Services\ProductionData\ProductionDataMirror::class)->writesUnlocked()"
        >
            <x-slot:actions>
                <a href="{{ route('live.sites.index') }}" wire:navigate class="rounded-lg bg-amber-950/10 px-3 py-1.5 text-sm font-semibold hover:bg-amber-950/15">
                    {{ __('Production sites') }}
                </a>
            </x-slot:actions>
        </x-production-data-banner>
        <x-production-data-nav :connection="$productionConnection" />
    @endif

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail
        :items="$settingsBreadcrumbs"
        :site="$site"
        doc-contextual
        :contextual-doc-slug="$contextualDocSlug"
        class="mb-6"
    />

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            {{-- No floating hero on merged-chrome sections — one outer dply-card.
                 Other sections keep the classic hero-card. --}}
            @if (! $usesMergedChrome)
            <x-hero-card
                :eyebrow="$productionConnection ? __('Production · :section', ['section' => $workspaceTitle]) : $workspaceTitle"
                :title="$sectionHeader['title']"
                :description="$sectionDescription"
                :icon="\Illuminate\Support\Str::after($sectionHeader['icon'], 'heroicon-o-')"
            >
                @if ($headerRoleLabel !== null)
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset {{ $headerRoleTone }}"
                          title="{{ __('Your access level for this :resource', ['resource' => strtolower($resourceNoun)]) }}">
                        @if ($headerIsDeployer)
                            <x-heroicon-m-rocket-launch class="h-3 w-3" aria-hidden="true" />
                        @elseif ($headerCanUpdateSite)
                            <x-heroicon-m-pencil-square class="h-3 w-3" aria-hidden="true" />
                        @else
                            <x-heroicon-m-eye class="h-3 w-3" aria-hidden="true" />
                        @endif
                        {{ $headerRoleLabel }}
                    </span>
                @endif
            </x-hero-card>
            @endif

            {{-- Outside <main>: it's invisible, and space-y-6 counts hidden
                 elements as siblings — inside, it gave the first visible card a
                 phantom top margin the sidebar column doesn't have. --}}
            @if ($watchedConsoleRunId)
                <div wire:poll.3s="resolveWatchedConsoleAction" class="hidden" aria-hidden="true"></div>
            @endif

            <main @class([
                'min-w-0',
                'space-y-6' => ! $usesMergedChrome,
                'mt-8' => ! $usesMergedChrome,
            ])>
                {{-- On merged-chrome sections the banner renders inside the
                     card. Other sections keep it above the panel. --}}
                @if (! $usesMergedChrome)
                    @include('livewire.sites.settings.partials._console-action-banner')
                @endif

                <div
                    role="tabpanel"
                    id="site-settings-panel"
                    aria-labelledby="site-settings-sidebar"
                    @class(['space-y-6' => ! $usesMergedChrome])
                >
                    @if ($section === 'general')
                        @if ($isContainerWorkspace && ! $site->usesFunctionsRuntime())
                            @include('livewire.sites.partials.container-dashboard')
                        @endif

                        @if ($site->usesFunctionsRuntime())
                            @include('livewire.sites.partials.serverless-dashboard')
                        @endif

                        @if (! $isContainerWorkspace)
                            {{-- choose-app CTA (if any) stays outside; everything
                                 else shares one merged card like server Settings. --}}
                            @if ($site->canRechooseApp())
                                @include('livewire.sites.settings.partials.general-tab', ['generalTabChooseAppOnly' => true])
                            @endif

                            <section class="dply-card min-w-0 overflow-hidden p-0">
                                @include('livewire.sites.settings.partials.general-tab', ['generalTabSkipChooseApp' => true])

                                @if ($generalRecentDeployments->isNotEmpty())
                                    @include('livewire.sites.partials.recent-deployments', [
                                        'deployments' => $generalRecentDeployments,
                                        'asStrip' => true,
                                    ])
                                @endif

                                <div class="px-5 py-5 sm:px-6">
                                    <x-cli-snippet :commands="[
                                        ['label' => __('Print primary URL'), 'command' => 'dply sites:url '.$site->slug],
                                        ['label' => __('Diagnose site'), 'command' => 'dply sites:doctor '.$site->slug],
                                        ['label' => __('Rename site'), 'command' => 'dply sites:rename '.$site->slug.' --name=\'New name\' --slug=new-slug'],
                                        ['label' => __('Export full config'), 'command' => 'dply sites:export:config '.$site->slug.' --to=site.json'],
                                        ['label' => __('Export deploy manifest'), 'command' => 'dply sites:export:manifest '.$site->slug.' --to=manifest.json'],
                                        ['label' => __('List all sites'), 'command' => 'dply sites:list'],
                                    ]" />
                                </div>
                            </section>
                        @else
                            {{-- general-tab (which carries the banner below its
                                 Overview strip) is skipped for container
                                 workspaces — render the banner here instead. --}}
                            @include('livewire.sites.settings.partials._console-action-banner')
                        @endif
                    @elseif ($section === 'settings')
                        <section class="dply-card min-w-0 overflow-hidden p-0">
                            <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                            @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])
                            @include('livewire.sites.settings.partials.settings-tab')
                        </section>
                    @elseif ($section === 'routing')
                        <section class="dply-card min-w-0 overflow-hidden p-0">
                            <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                            @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])
                            @include('livewire.sites.settings.partials.routing')
                        </section>
                    @elseif ($section === 'backends')
                        @livewire(\App\Livewire\Sites\Backends::class, ['server' => $server, 'site' => $site], key('backends-'.$site->id))
                    @elseif ($section === 'certificates')
                        <section class="dply-card min-w-0 overflow-hidden p-0">
                            <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                            @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])
                            @include('livewire.sites.settings.partials.certificates')
                        </section>
                    @elseif ($section === 'repository')
                        <section class="dply-card min-w-0 overflow-hidden p-0">
                            <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            />
                            @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])
                            @include('livewire.sites.settings.partials.repository')
                        </section>
                    @elseif ($section === 'deploy')
                        @if ($functionsHost)
                            @include('livewire.sites.settings.partials.deploy-recipe')
                        @else
                            @php
                                $deployRedirect = route('sites.deployments.index', [$server, $site]);
                            @endphp
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-6 text-sm text-brand-moss">
                                {{ __('Deploy settings moved to Deployments, Repository, and Pipeline.') }}
                                <a href="{{ $deployRedirect }}" wire:navigate class="ml-1 font-semibold text-brand-forest hover:underline">{{ __('Open Deployments') }}</a>
                                <span class="text-brand-mist" aria-hidden="true"> · </span>
                                <a href="{{ route('sites.pipeline', [$server, $site]) }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('Open Pipeline') }}</a>
                            </div>
                        @endif
                    @elseif ($section === 'runtime')
                        <section class="dply-card min-w-0 overflow-hidden p-0">
                            <x-workspace-panel-head
                                dense
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                            @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])

                            @if ($site->usesFunctionsRuntime())
                                @include('livewire.sites.settings.partials.runtime-serverless')
                            @else
                                @include('livewire.sites.settings.partials.runtime-workspace')
                            @endif
                        </section>
                    @elseif ($section === 'system-user')
                        @if (workspace_surface_coming_soon('site_system_user'))
                            <x-workspace-coming-soon
                                :server="$site->server"
                                icon="heroicon-o-user"
                                :title="__('System user')"
                                :description="__('Run this site under its own dedicated Linux user — isolated home, permissions, and SSH — so a compromise or runaway process stays contained to one site.')"
                                :eyebrow="__('System user preview')"
                                :lines="[
                                    ['tone' => 'cmd', 'text' => '~ $ id dply-site'],
                                    ['tone' => 'muted', 'text' => 'uid=1042(dply-site) gid=1042'],
                                    ['tone' => 'muted', 'text' => 'home=/home/dply-site  shell=/bin/bash'],
                                    ['tone' => 'ok', 'text' => 'isolated · least-privilege'],
                                ]"
                                :features="[
                                    ['icon' => 'user', 'title' => __('Dedicated user'), 'body' => __('Each site gets its own Linux user and home directory, not a shared one.')],
                                    ['icon' => 'lock-closed', 'title' => __('Contained blast radius'), 'body' => __('Permissions are scoped so one site cannot read or write another.')],
                                    ['icon' => 'key', 'title' => __('Per-site SSH'), 'body' => __('Grant SSH to just this site without handing over the whole box.')],
                                    ['icon' => 'arrows-right-left', 'title' => __('Safe to change'), 'body' => __('Reassign ownership and re-permission the tree without a redeploy.')],
                                ]"
                            />
                        @else
                            <section class="dply-card min-w-0 overflow-hidden p-0">
                                <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                                @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])

                                @include('livewire.sites.settings.partials.system-user')
                            </section>
                        @endif
                    @elseif ($section === 'laravel-stack')
                        <section class="dply-card min-w-0 overflow-hidden p-0">
                            <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                            @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])

                            @include('livewire.sites.settings.partials.laravel-stack')
                        </section>
                    @elseif ($section === 'rails-stack')
                        @include('livewire.sites.settings.partials.rails.workspace')
                    @elseif ($section === 'wordpress')
                        @livewire('sites.wordpress.wordpress-section', ['site' => $site], key('wordpress-section-'.$site->id))
                    @elseif ($section === 'worker-fleet')
                        @include('livewire.sites.settings.partials.worker-fleet')
                    @elseif ($section === 'environment')
                        @include('livewire.sites.settings.partials.environment')
                    @elseif ($section === 'resources')
                        <section class="dply-card min-w-0 overflow-hidden p-0">
                            <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                            @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])

                            @if ($isContainerWorkspace)
                                {{-- Container/Cloud sites keep their managed Cloud resources
                                     panel (CloudDatabase/CloudWorker), now embedded in the
                                     workspace chrome. VM sites use the bindings hub. --}}
                                @livewire(\App\Livewire\Sites\Resources::class, ['server' => $server, 'site' => $site, 'embedded' => true], key('cloud-resources-'.$site->id))
                            @else
                                {{-- Own Livewire component (not an @include) so the heavy
                                     binding graph + modal only re-render on their own
                                     state, not on every parent round-trip (polls, etc.). --}}
                                @livewire(\App\Livewire\Sites\ResourceMap::class, ['server' => $server, 'site' => $site], key('resource-map-'.$site->id))
                            @endif
                        </section>
                    @elseif ($section === 'logs')
                        @if (workspace_surface_coming_soon('site_logs'))
                            <x-workspace-coming-soon
                                :server="$site->server"
                                icon="heroicon-o-clipboard-document-list"
                                :title="__('Logs')"
                                :description="__('Tail this site\'s application, web server, and deploy logs in one place — searchable, filterable, and streamed live without SSHing into the box.')"
                                :eyebrow="__('Log stream preview')"
                                :lines="[
                                    ['tone' => 'cmd', 'text' => '~ $ dply logs --tail'],
                                    ['tone' => 'muted', 'text' => '12:04 [nginx] GET / 200 14ms'],
                                    ['tone' => 'muted', 'text' => '12:04 [php] production.INFO cache warmed'],
                                    ['tone' => 'ok', 'text' => 'streaming · 3 sources · live'],
                                ]"
                                :features="[
                                    ['icon' => 'bolt', 'title' => __('Live tail'), 'body' => __('Watch requests and errors stream in as they happen — no refresh.')],
                                    ['icon' => 'magnifying-glass', 'title' => __('Search & filter'), 'body' => __('Filter by source, level, or text to find the line that matters.')],
                                    ['icon' => 'square-3-stack-3d', 'title' => __('Every source'), 'body' => __('App, web server, and deploy logs unified into one view.')],
                                    ['icon' => 'arrow-down-tray', 'title' => __('Export'), 'body' => __('Pull a window of logs out for an incident or a teammate.')],
                                ]"
                            />
                        @elseif ($site->usesFunctionsRuntime())
                            @livewire('serverless.logs-panel', ['site' => $site], key('serverless-logs-'.$site->id))
                        @else
                            <section class="dply-card min-w-0 overflow-hidden p-0">
                                <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                                @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])

                                @include('livewire.sites.settings.partials.logs', ['logsMergedChrome' => true])
                            </section>
                        @endif
                    @elseif ($section === 'platform' && $site->usesFunctionsRuntime())
                        @livewire('serverless.platform-panel', ['site' => $site], key('serverless-platform-'.$site->id))
                    @elseif ($section === 'notifications')
                        @if (workspace_surface_coming_soon('site_notifications'))
                            <x-workspace-coming-soon
                                :server="$site->server"
                                icon="heroicon-o-bell"
                                :title="__('Notifications')"
                                :description="__('Get told the moment a deploy fails, a certificate is about to expire, or the site goes down — routed to the channels your team already lives in.')"
                                :eyebrow="__('Notifications preview')"
                                :lines="[
                                    ['tone' => 'cmd', 'text' => '~ $ dply notifications'],
                                    ['tone' => 'muted', 'text' => 'deploy.failed   → #deploys (slack)'],
                                    ['tone' => 'muted', 'text' => 'cert.expiring   → ops@ (email)'],
                                    ['tone' => 'ok', 'text' => '2 rules · 3 channels armed'],
                                ]"
                                :features="[
                                    ['icon' => 'rocket-launch', 'title' => __('Deploy alerts'), 'body' => __('Know instantly when a build or release fails — with the error attached.')],
                                    ['icon' => 'shield-exclamation', 'title' => __('Uptime & certs'), 'body' => __('Downtime and expiring TLS surface before your users notice.')],
                                    ['icon' => 'chat-bubble-left-right', 'title' => __('Your channels'), 'body' => __('Email, Slack, Discord, or a plain webhook — your choice per event.')],
                                    ['icon' => 'adjustments-horizontal', 'title' => __('Per-event rules'), 'body' => __('Route each event type to the right place, mute the noise.')],
                                ]"
                            />
                        @else
                            <section class="dply-card min-w-0 overflow-hidden p-0">
                                <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                                @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])

                                @include('livewire.sites.settings.partials.notifications')
                            </section>
                        @endif
                    @elseif ($section === 'basic-auth')
                        <section class="dply-card min-w-0 overflow-hidden p-0">
                            <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                            @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])

                            @include('livewire.sites.settings.partials.basic-auth')
                        </section>
                    @elseif ($section === 'cli')
                        @if (workspace_surface_coming_soon('site_cli'))
                            @include('livewire.sites.settings.partials.cli')
                        @else
                            <section class="dply-card min-w-0 overflow-hidden p-0">
                                <x-workspace-panel-head
                                    class="border-b border-brand-ink/10"
                                    :icon="$sectionHeader['icon']"
                                    :title="$sectionHeader['title']"
                                    :note="__('Run commands here, or install the CLI on your machine.')"
                                >
                                    <x-slot:actions>
                                        <a
                                            href="{{ route('profile.cli') }}"
                                            wire:navigate
                                            class="dply-btn dply-btn-xs dply-btn-outline"
                                        >
                                            {{ __('Install & login') }}
                                            <x-heroicon-m-arrow-up-right class="h-3 w-3" />
                                        </a>
                                    </x-slot:actions>
                                </x-workspace-panel-head>

                                @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])
                                @include('livewire.sites.settings.partials.cli', ['cliNestedInShell' => true])
                            </section>
                        @endif
                    @elseif ($section === 'danger')
                        <section class="dply-card min-w-0 overflow-hidden p-0">
                            <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                :icon="$sectionHeader['icon']"
                                :title="$sectionHeader['title']"
                                :note="$sectionDescription"
                            >
                                <x-slot:actions>
                                    @include('livewire.sites.partials.header-role-badge')
                                </x-slot:actions>
                            </x-workspace-panel-head>

                            @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])

                            @include('livewire.sites.settings.partials.danger')
                        </section>
                    @endif
                </div>
            </main>
        </div>
    </div>

    <x-modal
        name="quick-domain-ssl-modal"
        :show="false"
        maxWidth="lg"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel"
        focusable
    >
        <div class="border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Quick SSL') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add SSL for this hostname') }}</h2>
            <p class="mt-2 text-sm leading-6 text-brand-moss">
                {{ __('Create a certificate request without leaving the routing workspace. Use this when the hostname already resolves here and is ready for HTTP validation.') }}
            </p>
        </div>

        <div class="space-y-5 px-6 py-6">
            <div class="rounded-xl border border-brand-ink/10 bg-slate-50/70 px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Hostname') }}</p>
                <p class="mt-2 font-mono text-sm text-brand-ink">{{ $quick_ssl_domain_hostname ?: __('No hostname selected') }}</p>
                <x-input-error :messages="$errors->get('quick_ssl_domain_hostname')" class="mt-2" />
            </div>

            @if ($quick_ssl_reachability !== null)
                @php($quick_ssl_behind_cloudflare = ! $quick_ssl_reachability['ok'] && ! empty($quick_ssl_reachability['behind_cloudflare']))
                @php($quick_ssl_panel_classes = $quick_ssl_reachability['ok']
                    ? 'border-emerald-200 bg-emerald-50/60'
                    : ($quick_ssl_behind_cloudflare ? 'border-sky-200 bg-sky-50/60' : 'border-amber-200 bg-amber-50/60'))
                <div class="rounded-xl border px-4 py-3 {{ $quick_ssl_panel_classes }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            @if ($quick_ssl_reachability['ok'])
                                <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800">
                                    <x-heroicon-o-check-circle class="h-4 w-4" aria-hidden="true" /> {{ __('Reachable here') }}
                                </p>
                                <p class="mt-1 text-xs leading-5 text-emerald-900">{{ __('Resolves to this server (:ip) and answers over HTTP — ready for validation.', ['ip' => $quick_ssl_reachability['server_ip']]) }}</p>
                            @elseif ($quick_ssl_behind_cloudflare)
                                <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-sky-800">
                                    <x-heroicon-o-cloud class="h-4 w-4" aria-hidden="true" /> {{ __('Behind Cloudflare') }}
                                </p>
                                <p class="mt-1 text-xs leading-5 text-sky-900">{{ __('“:host” is proxied through Cloudflare (resolves to :got), which already serves HTTPS at its edge — so an origin certificate here is optional. If you want end-to-end TLS to this server, the HTTP challenge is proxied through to this box and can still validate.', ['host' => $quick_ssl_domain_hostname, 'got' => implode(', ', $quick_ssl_reachability['resolved_ips'])]) }}</p>
                                @if (! empty($quick_ssl_reachability['error']))
                                    <p class="mt-1 text-xs leading-5 text-amber-900">{{ $quick_ssl_reachability['error'] }}</p>
                                @endif
                            @else
                                <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">
                                    <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" /> {{ __('Not reachable yet') }}
                                </p>
                                <p class="mt-1 text-xs leading-5 text-amber-900">{{ $quick_ssl_reachability['error'] }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="recheckQuickDomainSslReachability" wire:loading.attr="disabled" wire:target="recheckQuickDomainSslReachability" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.class="animate-spin" wire:target="recheckQuickDomainSslReachability" aria-hidden="true" />
                            {{ __('Re-check') }}
                        </button>
                    </div>
                    @if ($quick_ssl_behind_cloudflare)
                        <label class="mt-3 flex items-start gap-2 text-xs text-sky-900">
                            <input type="checkbox" wire:model.live="quick_ssl_force" class="mt-0.5 h-4 w-4 rounded border-sky-300 text-sky-700 focus:ring-sky-500">
                            <span>{{ __('Issue an origin certificate anyway — the HTTP challenge routes through Cloudflare to this server.') }}</span>
                        </label>
                    @elseif (! $quick_ssl_reachability['ok'])
                        <label class="mt-3 flex items-start gap-2 text-xs text-amber-900">
                            <input type="checkbox" wire:model.live="quick_ssl_force" class="mt-0.5 h-4 w-4 rounded border-amber-300 text-amber-700 focus:ring-amber-500">
                            <span>{{ __('Request anyway — DNS may still be propagating. The HTTP challenge will keep failing until the domain points here.') }}</span>
                        </label>
                    @endif
                </div>
            @endif

            <div>
                <x-input-label for="quick_ssl_provider_type" :value="__('Certificate provider')" />
                <select
                    id="quick_ssl_provider_type"
                    wire:model="quick_ssl_provider_type"
                    class="mt-2 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                >
                    <option value="{{ \App\Models\SiteCertificate::PROVIDER_LETSENCRYPT }}">{{ __("Let's Encrypt") }}</option>
                    <option value="{{ \App\Models\SiteCertificate::PROVIDER_ZEROSSL }}">{{ __('ZeroSSL') }}</option>
                </select>
                <p class="mt-2 text-xs leading-5 text-brand-moss">
                    @if ($quick_ssl_provider_type === \App\Models\SiteCertificate::PROVIDER_ZEROSSL)
                        {{ __('This quick path uses ZeroSSL HTTP file validation, then installs the downloaded certificate on the host.') }}
                    @else
                        {{ __('This quick path uses an HTTP challenge and starts the request immediately after you confirm.') }}
                    @endif
                </p>
                <x-input-error :messages="$errors->get('quick_ssl_provider_type')" class="mt-2" />
            </div>
        </div>

        <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
            <x-secondary-button type="button" wire:click="closeQuickDomainSslModal">
                {{ __('Cancel') }}
            </x-secondary-button>
            <x-primary-button type="button" wire:click="quickAddDomainSsl" wire:loading.attr="disabled" wire:target="quickAddDomainSsl" :disabled="! ($quick_ssl_reachability['ok'] ?? false) && ! $quick_ssl_force">
                <span wire:loading.remove wire:target="quickAddDomainSsl">
                    {{ $quick_ssl_provider_type === \App\Models\SiteCertificate::PROVIDER_ZEROSSL ? __('Save request') : __('Add SSL') }}
                </span>
                <span wire:loading wire:target="quickAddDomainSsl" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Working…') }}
                </span>
            </x-primary-button>
        </div>
    </x-modal>

    <x-slot name="modals">
        <x-modal
            name="laravel-ssh-setup-modal"
            :show="false"
            maxWidth="lg"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Remote setup') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Run this command on the server?') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('This executes once over SSH in your site’s deploy directory. Ensure backups and that you trust this environment.') }}
                </p>
            </div>

            <div class="space-y-4 px-6 py-6">
                @if ($this->laravelSshSetupPendingCommandPreview())
                    <div class="rounded-xl border border-brand-ink/10 bg-slate-50/70 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Command') }}</p>
                        <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-all font-mono text-xs text-brand-ink">{{ $this->laravelSshSetupPendingCommandPreview() }}</pre>
                    </div>
                @endif
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeLaravelSshSetupModal">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <x-primary-button type="button" wire:click="confirmLaravelSshSetup" wire:loading.attr="disabled" wire:target="confirmLaravelSshSetup">
                    <span wire:loading.remove wire:target="confirmLaravelSshSetup">{{ __('Run command') }}</span>
                    <span wire:loading wire:target="confirmLaravelSshSetup" class="inline-flex items-center justify-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Running…') }}
                    </span>
                </x-primary-button>
            </div>
        </x-modal>

        <x-modal
            name="site-system-user-assign-modal"
            :show="false"
            maxWidth="lg"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('System user') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Assign existing user') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('This updates file ownership under this site’s repository path and sets the PHP-FPM pool user. Ensure you have backups.') }}
                </p>
            </div>

            <div class="px-6 py-6">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Selected user') }}</p>
                <p class="mt-2 font-mono text-sm text-brand-ink">{{ $system_user_assign_username }}</p>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeSystemUserAssignModal">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="queueAssignSystemUser" wire:loading.attr="disabled" wire:target="queueAssignSystemUser">
                    <span wire:loading.remove wire:target="queueAssignSystemUser">{{ __('Confirm') }}</span>
                    <span wire:loading wire:target="queueAssignSystemUser" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Queueing…') }}
                    </span>
                </x-primary-button>
            </div>
        </x-modal>

        <x-modal
            name="site-reset-permissions-modal"
            :show="false"
            maxWidth="2xl"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <div class="flex gap-4">
                    <div class="shrink-0 rounded-full bg-brand-forest/10 p-2 text-brand-forest">
                        <x-heroicon-o-information-circle class="h-7 w-7" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-xl font-semibold text-brand-ink">{{ __('Are you sure?') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Please read carefully before proceeding.') }}</p>
                    </div>
                </div>
            </div>

            <div class="max-h-[min(70vh,32rem)] space-y-5 overflow-y-auto px-6 py-6 text-sm leading-6 text-brand-ink">
                <div>
                    <p class="font-semibold text-brand-ink">{{ __('What will happen') }}</p>
                    <p class="mt-2 text-brand-moss">
                        {{ __('Choosing Reset will run a one-time job over SSH on this site’s repository path. Ownership is set to the effective system user and the web server group, then directories and files receive typical secure modes (755 / 644). If :storage and :cache exist, those trees use 775 / 664 so Laravel can write logs and compiled files.', ['storage' => 'storage/', 'cache' => 'bootstrap/cache/']) }}
                    </p>
                    <p class="mt-3 text-brand-moss">
                        {{ __('In this case, ownership will be user :user and group :group.', ['user' => $site->effectiveSystemUser($this->server), 'group' => config('site_settings.vm_site_file_web_group', 'www-data')]) }}
                    </p>
                </div>

                <div>
                    <p class="font-semibold text-brand-ink">{{ __('Why you might need this') }}</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-brand-moss">
                        <li>{{ __('Accidental chmod/chown changes broke deploys or HTTP access.') }}</li>
                        <li>{{ __('The site shows errors because PHP or the web server cannot read or write expected paths.') }}</li>
                        <li>{{ __('You want a known-good permission baseline before debugging further.') }}</li>
                    </ul>
                </div>

                <div>
                    <p class="font-semibold text-brand-ink">{{ __('Considerations') }}</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-brand-moss">
                        <li>{{ __('Custom permission tweaks under this path will be overwritten.') }}</li>
                        <li>{{ __('The change is immediate on the server and may disrupt a site that relied on non-standard permissions.') }}</li>
                        <li>{{ __('There is no automatic undo; restore from backups if you need the previous state.') }}</li>
                        <li>{{ __('This targets the repository path only; it does not change pool config elsewhere on the server.') }}</li>
                    </ol>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeSystemUserResetPermissionsModal">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="queueResetSitePermissions" wire:loading.attr="disabled" wire:target="queueResetSitePermissions">
                    <span wire:loading.remove wire:target="queueResetSitePermissions">{{ __('Reset') }}</span>
                    <span wire:loading wire:target="queueResetSitePermissions" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" class="h-4 w-4" />
                        {{ __('Queueing…') }}
                    </span>
                </x-primary-button>
            </div>
        </x-modal>
    </x-slot>

    @include('livewire.partials.confirm-action-modal')
</div>
</div>
