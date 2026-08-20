<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta
        title="Features"
        description="One control plane for the servers you own: provision or bring your own, deploy from git, manage TLS, databases, cron, firewall, backups, and teams—with an API and CLI behind every action." />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-site-header active="features" />

    <main>
        @php
            $jump = [
                '#model' => 'How it fits',
                '#deploy' => 'Deploy & releases',
                '#servers' => 'Servers',
                '#sites' => 'Sites & TLS',
                '#edge' => 'Edge & Cloud',
                '#recovery' => 'Backups & recovery',
                '#teams' => 'Teams & projects',
                '#api' => 'API & CLI',
                '#coverage' => 'Full coverage',
                '#security' => 'Security',
            ];

            $modelSteps = [
                ['icon' => 'user-group', 'n' => '01', 'title' => 'Organization', 'body' => 'Every server, site, and credential belongs to an org. Invite people, switch context, and bill the whole team on one plan.'],
                ['icon' => 'key', 'n' => '02', 'title' => 'Credentials', 'body' => 'Cloud tokens and keys live encrypted in the org vault. Members run real workflows without copying secrets locally.'],
                ['icon' => 'server-stack', 'n' => '03', 'title' => 'Servers', 'body' => 'Provision from supported clouds or register any box over SSH. One inventory for commands, health, and config.'],
                ['icon' => 'globe-alt', 'n' => '04', 'title' => 'Sites & ship', 'body' => 'Map domains to runtimes, wire git, and deploy from the UI, a webhook, or your CI—every release tracked.'],
            ];

            $serverOps = [
                ['icon' => 'command-line', 'title' => 'Remote execution', 'body' => 'Run shell commands over SSH from the dashboard for quick fixes—no keys handed out to every laptop.'],
                ['icon' => 'signal', 'title' => 'Health checks', 'body' => 'Point at an HTTP endpoint and track whether the service answers, with status next to the server.'],
                ['icon' => 'circle-stack', 'title' => 'Databases', 'body' => 'Create databases and users on the box (MySQL, MariaDB, PostgreSQL) over SSH, kept with the server.'],
                ['icon' => 'clock', 'title' => 'Cron, queues & workers', 'body' => 'Managed crontab blocks, Supervisor programs, and dedicated Horizon worker pools—queue config, balancing strategy, and retry settings are environment-driven and live-editable from the panel.'],
                ['icon' => 'shield-exclamation', 'title' => 'Firewall', 'body' => 'Declarative UFW rules with presets and templates—open the ports you mean to, with a history you can review.'],
                ['icon' => 'cpu-chip', 'title' => 'Metrics', 'body' => 'CPU, memory, disk, and load with historical charts and deployment correlation in the same place.'],
            ];

            $siteRows = [
                ['title' => 'Runtimes', 'body' => 'Pick PHP, Node, or static per site—frontend and backend live on the same inventory, with domains and env attached to each.'],
                ['title' => 'Nginx & SSL', 'body' => 'Provision vhosts, add custom snippets, and keep HTTPS part of the site lifecycle—not a weekend chore.'],
                ['title' => 'Git & webhooks', 'body' => 'Wire a repository and branch, vault the deploy key, and build on push via a signed webhook your CI can call too.'],
                ['title' => 'Per-environment config', 'body' => 'Encrypted <code class="text-xs bg-brand-sand/50 px-1 rounded">.env</code>, post-deploy commands, and extra Nginx config sit beside the site they belong to.'],
            ];

            $edgeCards = [
                ['icon' => 'cloud', 'color' => 'forest', 'title' => 'Cloud apps', 'body' => 'App-first PaaS backed by DigitalOcean App Platform or AWS App Runner. Deploy from git—no server, no OS, no Nginx config to manage.'],
                ['icon' => 'bolt', 'color' => 'rust', 'title' => 'Serverless functions', 'body' => 'HTTP web functions via dply Serverless. Create, deploy, and invoke from the dashboard—billed flat per function, no cold-start infrastructure to provision.'],
                ['icon' => 'signal', 'color' => 'sage', 'title' => 'Managed Realtime', 'body' => 'A dply-hosted Pusher-compatible WebSocket relay built on Cloudflare Workers and DigitalOcean. Drop-in replacement for Pusher or Laravel Reverb—billed through dply, zero infra to run.'],
                ['icon' => 'queue-list', 'color' => 'sage', 'title' => 'Managed Queues', 'body' => 'A hosted job queue Laravel reaches with its built-in SQS driver—no package, no Redis to provision. Priced by capacity rather than per job, and included free on Serverless sites, where a shared job store is the difference between queues working and jobs vanishing.'],
                ['icon' => 'globe-alt', 'color' => 'forest', 'title' => 'CDN & edge storage', 'body' => 'Global Cloudflare CDN with R2 object storage and KV backing Edge sites. Assets, purge, and edge config are managed alongside the app—not in a separate provider console.'],
            ];

            $recoveryRows = [
                ['icon' => 'archive-box', 'title' => 'Backups with ownership', 'body' => 'Define what to capture for databases and files, where archives land, and what restore path the team follows.'],
                ['icon' => 'arrow-path', 'title' => 'Deployment history', 'body' => 'Every release is recorded with its output, so rollback is obvious and the timeline is easy to trace.'],
                ['icon' => 'map', 'title' => 'Migration as a guided op', 'body' => 'Move a site or rebuild a server with deploy settings, backups, health checks, and runbooks already in one home.'],
            ];

            $teamCards = [
                ['icon' => 'user-plus', 'title' => 'In-app invitations', 'body' => 'Bring teammates in through secure invite links—access granted in the app, not over Slack with raw tokens.'],
                ['icon' => 'clipboard-document-list', 'title' => 'Activity & audit', 'body' => 'Review who changed what across infrastructure so production changes are easy to trace.'],
                ['icon' => 'squares-2x2', 'title' => 'Project control plane', 'body' => 'Grouped health, shared variables, notification routing, and runbooks for a whole product area.'],
                ['icon' => 'bell-alert', 'title' => 'Alerts & routing', 'body' => 'Notification channels, event routing, quiet hours, and webhook-friendly delivery to the right operators.'],
            ];

            $securityItems = [
                ['icon' => 'lock-closed', 'title' => 'Encrypted secrets', 'body' => 'Deploy keys, webhook secrets, and env payloads are encrypted at rest.'],
                ['icon' => 'finger-print', 'title' => 'Two-factor auth', 'body' => 'Turn on 2FA once—it protects login across every org and site you can reach.'],
                ['icon' => 'shield-check', 'title' => 'Governed API access', 'body' => 'Org-scoped tokens and granular abilities replace long-lived root creds on laptops.'],
                ['icon' => 'check-badge', 'title' => 'Verified identity', 'body' => 'OAuth sign-in and a verified email; org roles still decide what you can change.'],
            ];

            $coverage = [
                ['Server provisioning', 'ok', 'Create or destroy servers via DigitalOcean, Hetzner, Linode, Vultr, UpCloud, AWS EC2, Azure, Oracle Cloud, and more; attach Custom servers over SSH. The OS and base image stay yours—no resident agent.'],
                ['Git deploys &amp; rollbacks', 'ok', 'Git remotes, signed webhooks, deploy hooks, atomic deploys with a <code class="text-xs bg-brand-sand/60 px-1 rounded">releases/</code> directory, and rollback to a prior release.'],
                ['PHP / Laravel / Node / static', 'ok', 'Site types for PHP-FPM, Node reverse proxy, and static; Laravel options like scheduler, Octane, and env in the deploy flow.'],
                ['Edge &amp; Cloud hosting', 'ok', 'Container apps on DigitalOcean App Platform or AWS App Runner via a unified EdgeBackend. Deploy from git without a server, Nginx config, or OS to maintain. Cloudflare CDN, R2 object storage, and KV included.'],
                ['Serverless functions (FaaS)', 'partial', 'HTTP web functions via dply Serverless—create, deploy, and invoke from the dashboard. Multi-language adapters and package-level features are in progress.'],
                ['Managed Realtime', 'ok', 'Pusher-compatible WebSocket relay built on Cloudflare Workers and DigitalOcean. Drop-in for Laravel Echo / Reverb, billed through dply, no relay infra to operate.'],
                ['Managed Queues', 'partial', 'SQS-compatible hosted job queue with depth, throughput, and failed-job retry in the dashboard. In beta: free for all queues while the data plane proves out, then priced per queue by capacity tier (always free on Serverless).'],
                ['Databases (MySQL, MariaDB, PostgreSQL)', 'ok', 'Create databases and users on the server over SSH through the provisioning paths.'],
                ['SSL (Let\'s Encrypt)', 'ok', 'Certbot over SSH for site domains; renewal follows the server\'s certbot setup.'],
                ['Firewall (UFW)', 'ok', 'Per-server UFW rules with presets, templates, apply, status, and recent history. Hetzner cloud firewall managed via provider API.'],
                ['Cron &amp; Supervisor', 'ok', 'Managed crontab blocks and Supervisor programs tied to servers and sites.'],
                ['Worker pools &amp; Horizon', 'ok', 'Dedicated queue-worker servers with managed Laravel Horizon config. Queues, processes, balancing strategy, memory limit, and retry settings are environment-driven and live-editable from the panel.'],
                ['Monitoring (CPU / RAM / disk)', 'ok', 'Server metrics, historical charts, deployment correlation, diagnostics, and project-aware drilldowns.'],
                ['Backups', 'ok', 'Database and file backup planning, storage destinations, retention, and restore-oriented guidance.'],
                ['Teams &amp; audit', 'ok', 'Organizations, invitations, roles, and audit-log entries for infrastructure actions.'],
                ['WordPress', 'partial', 'Run WordPress as a PHP site; there is no dedicated WP installer or WP-CLI panel today.'],
                ['OS hardening (Fail2Ban, auto-updates)', 'ok', 'Fail2Ban and unattended-upgrades (security-only, no auto-reboot) are configured at provision time, alongside UFW and TLS. Deeper, image-specific hardening can still live in server recipes.'],
                ['Redis &amp; extra services', 'roadmap', 'Install and configure on the server outside the dedicated DB wizard, or encode it in server recipes.'],
            ];
            $badge = [
                'ok' => ['Supported', 'text-emerald-700'],
                'partial' => ['Partial', 'text-amber-800'],
                'roadmap' => ['Recipes', 'text-brand-ink/70'],
            ];
        @endphp

        <section class="px-4 py-12 pb-24 sm:px-6 sm:py-16 lg:px-8">
            <div class="mx-auto max-w-6xl">
                {{-- One surface: sand identity + flush jump strip + hairline sections --}}
                <div class="dply-card min-w-0 overflow-hidden p-0">
                    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-6 sm:px-6 sm:py-7">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ config('app.name') }}</p>
                        <h1 class="mt-1.5 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">
                            {{ __('Features') }}
                        </h1>
                        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-brand-moss sm:text-base">
                            {{ __('One control plane for the servers you own — provision or bring your own, deploy from git, and manage TLS, databases, cron, firewall, and backups. Cloud and Edge hosting add container apps, serverless functions, and managed realtime under the same org.') }}
                        </p>
                    </div>

                    <div class="sticky top-16 z-10 border-b border-brand-ink/10 bg-white/95 px-4 py-3 backdrop-blur-md sm:px-5">
                        <nav class="flex flex-wrap gap-1.5" aria-label="{{ __('On this page') }}">
                            @foreach ($jump as $href => $label)
                                <a href="{{ $href }}" class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold text-brand-moss transition-colors hover:bg-brand-sand/40 hover:text-brand-ink">{{ $label }}</a>
                            @endforeach
                        </nav>
                    </div>

                    <div class="divide-y divide-brand-ink/10">
                        {{-- Operating model --}}
                        <section id="model" class="scroll-mt-28">
                            <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('How everything fits together') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('One hierarchy, one trust boundary — access and data flow through a single chain.') }}</p>
                            </div>
                            <div class="divide-y divide-brand-ink/10">
                                @foreach ($modelSteps as $step)
                                    <div class="flex gap-4 px-5 py-4 sm:px-6">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-forest/10 text-brand-forest">
                                            <x-dynamic-component :component="'heroicon-o-' . $step['icon']" class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center justify-between gap-3">
                                                <h3 class="text-sm font-semibold text-brand-ink">{{ $step['title'] }}</h3>
                                                <span class="shrink-0 text-xs font-bold tracking-widest text-brand-mist">{{ $step['n'] }}</span>
                                            </div>
                                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $step['body'] }}</p>
                                        </div>
                                    </div>
                                @endforeach
                                <div class="bg-brand-sand/10 px-5 py-4 sm:px-6">
                                    <p class="text-sm leading-relaxed text-brand-moss">
                                        <span class="font-semibold text-brand-ink">{{ __('The mental model:') }}</span>
                                        {{ __('the organization is the trust boundary. Credentials never leave it, servers and sites inherit it, and the audit log records who did what—across every surface.') }}
                                    </p>
                                </div>
                            </div>
                        </section>

                        {{-- Deploy & releases --}}
                        <section id="deploy" class="scroll-mt-28">
                            <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('Deploys you can trigger, track, and roll back') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Git in, releases out — every path runs the same code over SSH into the same history.') }}</p>
                            </div>
                            <div class="divide-y divide-brand-ink/10">
                                <div class="flex gap-4 px-5 py-4 sm:px-6">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-gold/20 text-brand-rust">
                                        <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Same deploy from UI, git, or CI') }}</h3>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('A push, a button, or an org-scoped API call all hit the same flow. Keep CI for build and test; let :app push to the runtime.', ['app' => config('app.name')]) }}</p>
                                        <div class="mt-3 rounded-lg border border-brand-ink/10 bg-brand-ink px-4 py-3 font-mono text-xs text-brand-sand/90 sm:max-w-md">
                                            <div><span class="text-brand-gold">$</span> git push origin main</div>
                                            <div class="mt-1 text-brand-mist"># signed webhook fires</div>
                                            <div class="mt-1 text-brand-sage">→ release 184 · live · audited</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex gap-4 px-5 py-4 sm:px-6">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-sage">
                                        <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Atomic releases') }}</h3>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Each deploy is a fresh release directory with the') }} <code class="text-xs bg-brand-sand/50 px-1 rounded">current</code> {{ __('symlink flipped on success—no half-deployed states.') }}</p>
                                    </div>
                                </div>
                                <div class="flex gap-4 px-5 py-4 sm:px-6">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-gold/20 text-brand-rust">
                                        <x-heroicon-o-arrow-uturn-left class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Rollback without heroics') }}</h3>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('When a release misbehaves, flip back to a prior one from history—no SSH-around-until-it-works.') }}</p>
                                    </div>
                                </div>
                                <div class="flex gap-4 px-5 py-4 sm:px-6">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-forest/10 text-brand-forest">
                                        <x-heroicon-o-variable class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Env & secrets per site') }}</h3>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Per-environment') }} <code class="text-xs bg-brand-sand/50 px-1 rounded">.env</code> {{ __('content and deploy keys are encrypted at rest and applied during the deploy.') }}</p>
                                    </div>
                                </div>
                                <div class="flex gap-4 px-5 py-4 sm:px-6">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-sage">
                                        <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Laravel-friendly') }}</h3>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Octane ports, scheduler toggles, post-deploy commands, and release retention—configured next to the site, not in a playbook.') }}</p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        {{-- Servers --}}
                        <section id="servers" class="scroll-mt-28">
                            <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('The server record is your control plane') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Day-two operations — provision from a cloud or attach any box over SSH, then operate it without leaving the console.') }}</p>
                            </div>
                            <div class="divide-y divide-brand-ink/10">
                                @foreach ($serverOps as $op)
                                    <div class="flex gap-4 px-5 py-4 sm:px-6">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-sage">
                                            <x-dynamic-component :component="'heroicon-o-' . $op['icon']" class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-semibold text-brand-ink">{!! $op['title'] !!}</h3>
                                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{!! $op['body'] !!}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        {{-- Sites & TLS --}}
                        <section id="sites" class="scroll-mt-28">
                            <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('Sites, TLS & runtimes') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('From hostname to HTTPS.') }}</p>
                            </div>
                            <div class="divide-y divide-brand-ink/10">
                                <div class="px-5 py-4 sm:px-6">
                                    <p class="text-sm leading-relaxed text-brand-moss">{{ __('A') }} <strong class="font-medium text-brand-forest">{{ __('site') }}</strong> {{ __('is how traffic reaches your code—hostname, runtime, document root, and deploy settings, all bound to the server it runs on.') }}</p>
                                    <ul class="mt-3 space-y-2 text-sm text-brand-moss">
                                        <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" /> {{ __("PHP-FPM, Node behind a reverse proxy, or static/HTML") }}</li>
                                        <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" /> {{ __("Certbot / Let's Encrypt with certificate status") }}</li>
                                        <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" /> {{ __('GitHub, GitLab & Bitbucket via OAuth') }}</li>
                                        <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" /> {{ __('Edge & Cloud sites — skip the server entirely (see below)') }}</li>
                                    </ul>
                                </div>
                                @foreach ($siteRows as $row)
                                    <div class="px-5 py-4 sm:px-6">
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ $row['title'] }}</h3>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{!! $row['body'] !!}</p>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        {{-- Edge & Cloud --}}
                        <section id="edge" class="scroll-mt-28">
                            <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('Edge & Cloud hosting') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Code without a server — billed, governed, and deployed from the same control plane as your VMs.') }}</p>
                            </div>
                            <div class="divide-y divide-brand-ink/10">
                                @foreach ($edgeCards as $card)
                                    <div class="flex gap-4 px-5 py-4 sm:px-6">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-{{ $card['color'] }}/15 text-brand-{{ $card['color'] }}">
                                            <x-dynamic-component :component="'heroicon-o-' . $card['icon']" class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-semibold text-brand-ink">{{ $card['title'] }}</h3>
                                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $card['body'] }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        {{-- Backups & recovery --}}
                        <section id="recovery" class="scroll-mt-28">
                            <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('Backups, rollback & recovery as one story') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Confidence, not just deploy buttons.') }}</p>
                            </div>
                            <div class="divide-y divide-brand-ink/10">
                                @foreach ($recoveryRows as $row)
                                    <div class="flex gap-4 px-5 py-4 sm:px-6">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-sage">
                                            <x-dynamic-component :component="'heroicon-o-' . $row['icon']" class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-semibold text-brand-ink">{{ $row['title'] }}</h3>
                                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $row['body'] }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        {{-- Teams & projects --}}
                        <section id="teams" class="scroll-mt-28">
                            <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('Teams, billing & projects') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('People & coordination — multi-tenant by design.') }}</p>
                            </div>
                            <div class="divide-y divide-brand-ink/10">
                                <div class="px-5 py-4 sm:px-6">
                                    <p class="text-sm leading-relaxed text-brand-moss">{{ __('Your production org stays separate from personal experiments, billing rolls up per organization, and projects group a product stack into one operating surface.') }}</p>
                                </div>
                                <div class="bg-brand-sand/10 px-5 py-4 sm:px-6">
                                    <p class="text-sm leading-relaxed text-brand-moss">
                                        <span class="font-semibold text-brand-ink">{{ __('One plan, whole org.') }}</span>
                                        {{ __("Trials and limits—how many servers and sites you can run—are counted for the entire organization. There's no per-site line on your invoice. Your profile, 2FA, and OAuth stay personal and follow you across every org.") }}
                                    </p>
                                </div>
                                @foreach ($teamCards as $card)
                                    <div class="flex gap-4 px-5 py-4 sm:px-6">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-sage">
                                            <x-dynamic-component :component="'heroicon-o-' . $card['icon']" class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-semibold text-brand-ink">{!! $card['title'] !!}</h3>
                                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{!! $card['body'] !!}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        {{-- API & CLI --}}
                        <section id="api" class="scroll-mt-28">
                            <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('An API and a CLI behind every action') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Scriptable platform — CI pipelines, agents, and scripts run the same operations as the dashboard.') }}</p>
                            </div>
                            <div class="divide-y divide-brand-ink/10">
                                <div class="flex gap-4 px-5 py-4 sm:px-6">
                                    <x-heroicon-o-code-bracket class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('OpenAPI 3 spec') }}</h3>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Sites, deployments, previews, domains, cache purge, usage, and logs in one file—generate clients, mock for tests, drop into Postman or Bruno.') }} <a href="{{ url('/openapi/edge.json') }}" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">{{ __('View the spec') }}</a></p>
                                    </div>
                                </div>
                                <div class="flex gap-4 px-5 py-4 sm:px-6">
                                    <x-heroicon-o-key class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Org-scoped tokens') }}</h3>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Granular abilities (') }}<code class="text-xs bg-brand-sand/50 px-1 rounded">edge.read</code>, <code class="text-xs bg-brand-sand/50 px-1 rounded">edge.deploy</code>, <code class="text-xs bg-brand-sand/50 px-1 rounded">edge.write</code>{{ __('), minted in Settings and revocable anytime. The CLI stores them in your OS keyring.') }}</p>
                                    </div>
                                </div>
                                <div class="px-5 py-4 sm:px-6">
                                    <div class="overflow-hidden rounded-lg border border-brand-ink/10 bg-brand-ink shadow-sm">
                                        <div class="flex items-center gap-1.5 border-b border-white/10 px-4 py-2.5">
                                            <span class="h-2.5 w-2.5 rounded-full bg-white/20"></span>
                                            <span class="h-2.5 w-2.5 rounded-full bg-white/20"></span>
                                            <span class="h-2.5 w-2.5 rounded-full bg-white/20"></span>
                                            <span class="ml-3 text-xs font-mono text-brand-mist">dply — terminal</span>
                                        </div>
                                        <div class="space-y-1 px-4 py-4 font-mono text-xs leading-relaxed text-brand-sand/90 sm:text-sm">
                                            <div><span class="text-brand-mist"># install &amp; sign in via OAuth device flow</span></div>
                                            <div><span class="text-brand-gold">$</span> curl -fsSL {{ route('cli.install') }} | bash -s -- --login</div>
                                            <div class="text-brand-sage">✓ authenticated · token stored in keyring</div>
                                            <div class="pt-2"><span class="text-brand-gold">$</span> dply edge deploy</div>
                                            <div class="text-brand-sage">→ release 184 deploying… done in 12s</div>
                                            <div class="pt-2"><span class="text-brand-gold">$</span> dply server system-users</div>
                                            <div class="text-brand-mist">deploy  web-1  active</div>
                                        </div>
                                    </div>
                                    <p class="mt-3 text-xs text-brand-mist">{{ __('The CLI is a PHP binary—install via the one-liner and authenticate through the OAuth device flow. Same code path as a GitHub webhook or a button click—no dashboard-only features to give up.') }}</p>
                                </div>
                            </div>
                        </section>

                        {{-- Coverage table --}}
                        <section id="coverage" class="scroll-mt-28">
                            <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __("What's included today") }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('No marketing asterisks — the honest state of each area.') }}</p>
                            </div>
                            <div class="px-5 py-3 sm:px-6">
                                <p class="flex flex-wrap items-center gap-3 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-1 text-emerald-900"><span class="h-1.5 w-1.5 rounded-full bg-emerald-600" aria-hidden="true"></span> {{ __('Supported') }}</span>
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-2.5 py-1 text-amber-950"><span class="h-1.5 w-1.5 rounded-full bg-amber-600" aria-hidden="true"></span> {{ __('Partial / different model') }}</span>
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-mist/20 px-2.5 py-1 text-brand-ink"><span class="h-1.5 w-1.5 rounded-full bg-brand-mist" aria-hidden="true"></span> {{ __('Roadmap / use recipes') }}</span>
                                </p>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead>
                                        <tr class="border-y border-brand-ink/10 bg-brand-sand/10 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                            <th scope="col" class="px-5 py-2.5 sm:px-6 w-56">{{ __('Area') }}</th>
                                            <th scope="col" class="px-5 py-2.5 sm:px-6">{{ __('In :app', ['app' => config('app.name')]) }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                                        @foreach ($coverage as $row)
                                            <tr>
                                                <td class="px-5 py-4 sm:px-6 font-medium align-top">{!! $row[0] !!}</td>
                                                <td class="px-5 py-4 sm:px-6 text-brand-moss leading-relaxed align-top">
                                                    <span class="{{ $badge[$row[1]][1] }} font-semibold">{{ $badge[$row[1]][0] }}</span> — {!! $row[2] !!}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        {{-- Security --}}
                        <section id="security" class="scroll-mt-28">
                            <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('Security & account hygiene') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('More than a password.') }}</p>
                            </div>
                            <div class="divide-y divide-brand-ink/10">
                                @foreach ($securityItems as $item)
                                    <div class="flex gap-4 px-5 py-4 sm:px-6">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-forest/10 text-brand-forest">
                                            <x-dynamic-component :component="'heroicon-o-' . $item['icon']" class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-semibold text-brand-ink">{{ $item['title'] }}</h3>
                                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $item['body'] }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    </div>
                </div>

                <p class="mt-6 text-center text-xs text-brand-mist">
                    {{ __('Curious what it costs?') }}
                    <a href="{{ route('pricing') }}" class="font-semibold text-brand-sage hover:text-brand-ink">{{ __('View pricing') }}</a>
                </p>
            </div>
        </section>

        {{-- CTA --}}
        <section class="border-t border-brand-ink/10 bg-brand-sand/25 px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-2xl font-bold tracking-tight text-brand-ink">{{ __('See it in your account') }}</h2>
                <p class="mt-3 text-sm leading-relaxed text-brand-moss sm:text-base">{{ __('Connect a provider, create your first server, and ship a real deploy—on infrastructure you already control.') }}</p>
                <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    @auth
                        <a href="{{ route('docs.index') }}" class="inline-flex w-full items-center justify-center rounded-xl bg-brand-ink px-6 py-3 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest sm:w-auto">{{ __('Open docs') }}</a>
                        <a href="{{ route('dashboard') }}" class="inline-flex w-full items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-6 py-3 text-sm font-semibold text-brand-ink transition-colors hover:bg-brand-sand/40 sm:w-auto">{{ __('Go to dashboard') }}</a>
                    @else
                        <a href="{{ route('register') }}" class="inline-flex w-full items-center justify-center rounded-xl bg-brand-ink px-6 py-3 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest sm:w-auto">{{ __('Start free trial') }}</a>
                        <a href="{{ route('pricing') }}" class="inline-flex w-full items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-6 py-3 text-sm font-semibold text-brand-ink transition-colors hover:bg-brand-sand/40 sm:w-auto">{{ __('Compare plans') }}</a>
                    @endauth
                </div>
            </div>
        </section>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
