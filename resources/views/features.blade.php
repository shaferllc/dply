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
    <div class="fixed inset-0 -z-10 bg-[radial-gradient(ellipse_100%_80%_at_50%_-30%,rgba(205,169,66,0.08),transparent_55%)]"></div>

    <x-site-header active="features" />

    <main>
        {{-- Hero --}}
        <section class="relative pt-16 pb-14 sm:pt-24 sm:pb-20 px-4 sm:px-6 lg:px-8 overflow-hidden">
            <div class="mx-auto max-w-3xl text-center">
                <p class="inline-flex items-center gap-2 rounded-full border border-brand-sage/25 bg-white/60 px-4 py-1.5 text-xs font-semibold tracking-wide text-brand-forest uppercase">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-gold" aria-hidden="true"></span>
                    The platform tour
                </p>
                <h1 class="mt-8 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl lg:leading-[1.08]">
                    One control plane for the
                    <span class="relative whitespace-nowrap">
                        <span class="relative z-10 text-brand-forest">servers you own</span>
                        <span class="absolute bottom-1 left-0 right-0 h-3 bg-brand-gold/35 -rotate-1 rounded-sm -z-0" aria-hidden="true"></span>
                    </span>
                </h1>
                <p class="mt-6 text-lg text-brand-moss leading-relaxed">
                    Provision from your cloud or bring any box over SSH. Deploy from git, manage TLS, databases, cron, firewall, and backups—and give the whole team one audited place to work. Need to skip the server entirely? Cloud and Edge hosting add container apps, serverless functions, and managed realtime under the same org.
                </p>
                <div class="mt-9 flex flex-col sm:flex-row items-center justify-center gap-4">
                    @auth
                        <a href="{{ route('dashboard') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/25 hover:bg-[#d4b24d] transition-colors">Open dashboard</a>
                    @else
                        <a href="{{ route('register') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/25 hover:bg-[#d4b24d] transition-colors">Start free trial</a>
                    @endauth
                    <a href="{{ route('pricing') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl border-2 border-brand-ink/15 bg-white/70 text-brand-ink text-sm font-semibold hover:border-brand-sage/40 hover:bg-white transition-colors">View pricing</a>
                </div>

                <nav class="mt-12 flex flex-wrap justify-center gap-2 text-sm" aria-label="On this page">
                    @php
                        $jump = [
                            '#model' => 'How it fits',
                            '#deploy' => 'Deploy &amp; releases',
                            '#servers' => 'Servers',
                            '#sites' => 'Sites &amp; TLS',
                            '#edge' => 'Edge &amp; Cloud',
                            '#recovery' => 'Backups &amp; recovery',
                            '#teams' => 'Teams &amp; projects',
                            '#api' => 'API &amp; CLI',
                            '#coverage' => 'Full coverage',
                            '#security' => 'Security',
                        ];
                    @endphp
                    @foreach ($jump as $href => $label)
                        <a href="{{ $href }}" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">{!! $label !!}</a>
                    @endforeach
                </nav>
            </div>
        </section>

        {{-- Operating model --}}
        <section id="model" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 scroll-mt-24">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">One hierarchy, one trust boundary</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">How everything fits together</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">Access and data flow through a single chain—so onboarding a teammate never means forwarding API tokens, and every action traces back to a member who already belongs to the org.</p>
                </div>

                <ol class="mt-14 grid gap-5 lg:grid-cols-4">
                    @php
                        $steps = [
                            ['icon' => 'user-group', 'n' => '01', 'title' => 'Organization', 'body' => 'Every server, site, and credential belongs to an org. Invite people, switch context, and bill the whole team on one plan.'],
                            ['icon' => 'key', 'n' => '02', 'title' => 'Credentials', 'body' => 'Cloud tokens and keys live encrypted in the org vault. Members run real workflows without copying secrets locally.'],
                            ['icon' => 'server-stack', 'n' => '03', 'title' => 'Servers', 'body' => 'Provision from supported clouds or register any box over SSH. One inventory for commands, health, and config.'],
                            ['icon' => 'globe-alt', 'n' => '04', 'title' => 'Sites & ship', 'body' => 'Map domains to runtimes, wire git, and deploy from the UI, a webhook, or your CI—every release tracked.'],
                        ];
                    @endphp
                    @foreach ($steps as $i => $step)
                        <li class="relative rounded-2xl border border-brand-ink/10 bg-white/80 p-6 shadow-sm">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest">
                                    <x-dynamic-component :component="'heroicon-o-' . $step['icon']" class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <span class="text-xs font-bold tracking-widest text-brand-mist">{{ $step['n'] }}</span>
                            </div>
                            <h3 class="mt-5 text-lg font-semibold text-brand-ink">{{ $step['title'] }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ $step['body'] }}</p>
                            @unless ($loop->last)
                                <span class="hidden lg:block absolute top-1/2 -right-[11px] -translate-y-1/2 text-brand-sage/50" aria-hidden="true">
                                    <x-heroicon-m-chevron-right class="h-5 w-5" />
                                </span>
                            @endunless
                        </li>
                    @endforeach
                </ol>

                <div class="mt-10 rounded-2xl border border-brand-ink/10 bg-brand-ink text-brand-cream px-6 py-7 sm:px-10">
                    <p class="text-sm font-medium text-brand-sand/90 leading-relaxed max-w-3xl">
                        <span class="text-brand-gold font-semibold">The mental model:</span> the organization is the trust boundary. Credentials never leave it, servers and sites inherit it, and the audit log records who did what—across every surface.
                    </p>
                </div>
            </div>
        </section>

        {{-- Deploy & releases (bento) --}}
        <section id="deploy" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-gradient-to-b from-white/60 to-brand-sand/20 scroll-mt-24">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">Git in, releases out</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Deploys you can trigger, track, and roll back</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">Connect a repo, wire a branch, and ship from the dashboard, a signed webhook after a push, or your pipeline—every path runs the same code over SSH and lands in the same deployment history.</p>
                </div>

                <div class="mt-14 grid gap-5 lg:grid-cols-3">
                    {{-- Wide dark card: deploy from anywhere --}}
                    <article class="lg:col-span-2 rounded-2xl border border-brand-ink/10 bg-brand-ink text-brand-cream p-8 sm:p-10 shadow-lg">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-8">
                            <div class="flex-1">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/10 text-brand-gold">
                                    <x-heroicon-o-bolt class="h-6 w-6" aria-hidden="true" />
                                </div>
                                <h3 class="mt-6 text-xl font-semibold text-brand-cream">Same deploy from UI, git, or CI</h3>
                                <p class="mt-3 text-brand-sand/85 leading-relaxed">A push, a button, or an org-scoped API call all hit the same flow. Keep CI for build and test; let {{ config('app.name') }} push to the runtime.</p>
                            </div>
                            <div class="shrink-0 rounded-xl border border-white/15 bg-black/30 px-4 py-3 font-mono text-xs text-brand-sand/90 w-full sm:w-auto sm:min-w-[230px]">
                                <div><span class="text-brand-gold">$</span> git push origin main</div>
                                <div class="mt-2 text-brand-mist"># signed webhook fires</div>
                                <div class="mt-3 text-brand-sage">→ release 184 · live · audited</div>
                            </div>
                        </div>
                    </article>

                    <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-8 shadow-sm">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage">
                            <x-heroicon-o-rectangle-stack class="h-6 w-6" aria-hidden="true" />
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-brand-ink">Atomic releases</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Each deploy is a fresh release directory with the <code class="text-xs bg-brand-sand/50 px-1 rounded">current</code> symlink flipped on success—no half-deployed states.</p>
                    </article>

                    <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-8 shadow-sm">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-gold/25 text-brand-rust">
                            <x-heroicon-o-arrow-uturn-left class="h-6 w-6" aria-hidden="true" />
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-brand-ink">Rollback without heroics</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">When a release misbehaves, flip back to a prior one from history—no SSH-around-until-it-works.</p>
                    </article>

                    <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-8 shadow-sm">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest">
                            <x-heroicon-o-variable class="h-6 w-6" aria-hidden="true" />
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-brand-ink">Env &amp; secrets per site</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Per-environment <code class="text-xs bg-brand-sand/50 px-1 rounded">.env</code> content and deploy keys are encrypted at rest and applied during the deploy.</p>
                    </article>

                    <article class="rounded-2xl border border-brand-ink/10 bg-gradient-to-b from-brand-sand/40 to-white/80 p-8 shadow-sm">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/70 text-brand-forest">
                            <x-heroicon-o-rocket-launch class="h-6 w-6" aria-hidden="true" />
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-brand-ink">Laravel-friendly</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Octane ports, scheduler toggles, post-deploy commands, and release retention—configured next to the site, not in a playbook.</p>
                    </article>
                </div>
            </div>
        </section>

        {{-- Servers --}}
        <section id="servers" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 scroll-mt-24">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">Day-two operations</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">The server record is your control plane</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">Provision from DigitalOcean, Hetzner, Linode, Vultr, UpCloud, Scaleway, Equinix Metal, Fly.io, AWS EC2, and more—or attach any machine over SSH. Then operate it without leaving the console.</p>
                </div>

                <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @php
                        $ops = [
                            ['icon' => 'command-line', 'title' => 'Remote execution', 'body' => 'Run shell commands over SSH from the dashboard for quick fixes—no keys handed out to every laptop.'],
                            ['icon' => 'signal', 'title' => 'Health checks', 'body' => 'Point at an HTTP endpoint and track whether the service answers, with status next to the server.'],
                            ['icon' => 'circle-stack', 'title' => 'Databases', 'body' => 'Create databases and users on the box (MySQL, MariaDB, PostgreSQL) over SSH, kept with the server.'],
                            ['icon' => 'clock', 'title' => 'Cron, queues &amp; workers', 'body' => 'Managed crontab blocks, Supervisor programs, and dedicated Horizon worker pools—queue config, balancing strategy, and retry settings are environment-driven and live-editable from the panel.'],
                            ['icon' => 'shield-exclamation', 'title' => 'Firewall', 'body' => 'Declarative UFW rules with presets and templates—open the ports you mean to, with a history you can review.'],
                            ['icon' => 'cpu-chip', 'title' => 'Metrics', 'body' => 'CPU, memory, disk, and load with historical charts and deployment correlation in the same place.'],
                        ];
                    @endphp
                    @foreach ($ops as $op)
                        <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm hover:shadow-md transition-shadow">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage">
                                <x-dynamic-component :component="'heroicon-o-' . $op['icon']" class="h-5 w-5" aria-hidden="true" />
                            </div>
                            <h3 class="mt-5 font-semibold text-brand-ink">{!! $op['title'] !!}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{!! $op['body'] !!}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Sites & TLS --}}
        <section id="sites" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-white/40 scroll-mt-24">
            <div class="mx-auto max-w-7xl lg:grid lg:grid-cols-12 lg:gap-12">
                <div class="lg:col-span-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">From hostname to HTTPS</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Sites, TLS &amp; runtimes</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">A <strong class="text-brand-forest font-medium">site</strong> is how traffic reaches your code—hostname, runtime, document root, and deploy settings, all bound to the server it runs on.</p>
                    <ul class="mt-8 space-y-3 text-sm text-brand-moss">
                        <li class="flex gap-3"><x-heroicon-m-check class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" /> PHP-FPM, Node behind a reverse proxy, or static/HTML</li>
                        <li class="flex gap-3"><x-heroicon-m-check class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" /> Certbot / Let's Encrypt with certificate status</li>
                        <li class="flex gap-3"><x-heroicon-m-check class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" /> GitHub, GitLab &amp; Bitbucket via OAuth</li>
                        <li class="flex gap-3"><x-heroicon-m-check class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" /> Edge &amp; Cloud sites — skip the server entirely (see below)</li>
                    </ul>
                </div>
                <div class="mt-10 lg:mt-0 lg:col-span-7 grid gap-5 sm:grid-cols-2">
                    <div class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Runtimes</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Pick PHP, Node, or static per site—frontend and backend live on the same inventory, with domains and env attached to each.</p>
                    </div>
                    <div class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Nginx &amp; SSL</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Provision vhosts, add custom snippets, and keep HTTPS part of the site lifecycle—not a weekend chore.</p>
                    </div>
                    <div class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Git &amp; webhooks</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Wire a repository and branch, vault the deploy key, and build on push via a signed webhook your CI can call too.</p>
                    </div>
                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-forest/5 p-6">
                        <h3 class="font-semibold text-brand-ink">Per-environment config</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Encrypted <code class="text-xs bg-brand-sand/50 px-1 rounded">.env</code>, post-deploy commands, and extra Nginx config sit beside the site they belong to.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- Edge & Cloud --}}
        <section id="edge" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-gradient-to-b from-brand-sand/20 to-white/70 scroll-mt-24">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">Code without a server</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Edge &amp; Cloud hosting</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">Deploy container apps via DigitalOcean App Platform or AWS App Runner without provisioning a server. Add serverless HTTP functions, a managed Pusher-compatible realtime relay, and global CDN storage—all billed, governed, and deployed from the same control plane as your VMs.</p>
                </div>

                <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest">
                            <x-heroicon-o-cloud class="h-5 w-5" aria-hidden="true" />
                        </div>
                        <h3 class="mt-5 font-semibold text-brand-ink">Cloud apps</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">App-first PaaS backed by DigitalOcean App Platform or AWS App Runner. Deploy from git—no server, no OS, no Nginx config to manage.</p>
                    </article>

                    <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-gold/25 text-brand-rust">
                            <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                        </div>
                        <h3 class="mt-5 font-semibold text-brand-ink">Serverless functions</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">HTTP web functions via DigitalOcean Functions. Create, deploy, and invoke from the dashboard—billed flat per function, no cold-start infrastructure to provision.</p>
                    </article>

                    <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage">
                            <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                        </div>
                        <h3 class="mt-5 font-semibold text-brand-ink">Managed Realtime</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">A dply-hosted Pusher-compatible WebSocket relay built on Cloudflare Workers and DigitalOcean. Drop-in replacement for Pusher or Laravel Reverb—billed through dply, zero infra to run.</p>
                    </article>

                    <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest">
                            <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                        </div>
                        <h3 class="mt-5 font-semibold text-brand-ink">CDN &amp; edge storage</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Global Cloudflare CDN with R2 object storage and KV backing Edge sites. Assets, purge, and edge config are managed alongside the app—not in a separate provider console.</p>
                    </article>
                </div>
            </div>
        </section>

        {{-- Backups & recovery --}}
        <section id="recovery" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 scroll-mt-24">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">Confidence, not just deploy buttons</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Backups, rollback &amp; recovery as one story</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">Production teams buy the confidence that they can restore data, roll back code, and move workloads safely—so {{ config('app.name') }} keeps that close to the app instead of scattered across docs.</p>
                </div>

                <div class="mt-14 grid gap-5 lg:grid-cols-3">
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-8 shadow-sm">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage">
                            <x-heroicon-o-archive-box class="h-6 w-6" aria-hidden="true" />
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-brand-ink">Backups with ownership</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Define what to capture for databases and files, where archives land, and what restore path the team follows.</p>
                    </article>
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-8 shadow-sm">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-gold/25 text-brand-rust">
                            <x-heroicon-o-arrow-path class="h-6 w-6" aria-hidden="true" />
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-brand-ink">Deployment history</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Every release is recorded with its output, so rollback is obvious and the timeline is easy to trace.</p>
                    </article>
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-8 shadow-sm">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest">
                            <x-heroicon-o-map class="h-6 w-6" aria-hidden="true" />
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-brand-ink">Migration as a guided op</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Move a site or rebuild a server with deploy settings, backups, health checks, and runbooks already in one home.</p>
                    </article>
                </div>
            </div>
        </section>

        {{-- Teams & projects --}}
        <section id="teams" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-gradient-to-b from-brand-sand/20 to-white/70 scroll-mt-24">
            <div class="mx-auto max-w-7xl lg:grid lg:grid-cols-12 lg:gap-12 lg:items-start">
                <div class="lg:col-span-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">People &amp; coordination</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Teams, billing &amp; projects</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">Multi-tenant by design: your production org stays separate from personal experiments, billing rolls up per organization, and projects group a product stack into one operating surface.</p>
                    <div class="mt-8 rounded-2xl border border-brand-gold/35 bg-gradient-to-br from-brand-gold/10 to-brand-sand/20 px-6 py-6">
                        <p class="text-sm text-brand-moss leading-relaxed"><span class="font-semibold text-brand-ink">One plan, whole org.</span> Trials and limits—how many servers and sites you can run—are counted for the entire organization. There's no per-site line on your invoice. Your profile, 2FA, and OAuth stay personal and follow you across every org.</p>
                    </div>
                </div>
                <div class="mt-10 lg:mt-0 lg:col-span-7 grid gap-5 sm:grid-cols-2">
                    @php
                        $teamCards = [
                            ['icon' => 'user-plus', 'title' => 'In-app invitations', 'body' => 'Bring teammates in through secure invite links—access granted in the app, not over Slack with raw tokens.'],
                            ['icon' => 'clipboard-document-list', 'title' => 'Activity &amp; audit', 'body' => 'Review who changed what across infrastructure so production changes are easy to trace.'],
                            ['icon' => 'squares-2x2', 'title' => 'Project control plane', 'body' => 'Grouped health, shared variables, notification routing, and runbooks for a whole product area.'],
                            ['icon' => 'bell-alert', 'title' => 'Alerts &amp; routing', 'body' => 'Notification channels, event routing, quiet hours, and webhook-friendly delivery to the right operators.'],
                        ];
                    @endphp
                    @foreach ($teamCards as $card)
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage">
                                <x-dynamic-component :component="'heroicon-o-' . $card['icon']" class="h-5 w-5" aria-hidden="true" />
                            </div>
                            <h3 class="mt-5 font-semibold text-brand-ink">{!! $card['title'] !!}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{!! $card['body'] !!}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- API & CLI (dark band) --}}
        <section id="api" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-brand-ink text-brand-cream scroll-mt-24">
            <div class="mx-auto max-w-7xl lg:grid lg:grid-cols-12 lg:gap-12 lg:items-center">
                <div class="lg:col-span-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-gold">Scriptable platform</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">An API and a CLI behind every action</h2>
                    <p class="mt-4 text-brand-sand/85 leading-relaxed">A public REST API with an <a href="{{ url('/openapi/edge.json') }}" class="font-semibold text-brand-gold underline decoration-brand-gold/40 underline-offset-2 hover:decoration-brand-gold">OpenAPI&nbsp;3 spec</a> and a PHP CLI mean CI pipelines, agents, and scripts run the same operations as the dashboard—against the same org and audit trail.</p>
                    <ul class="mt-8 space-y-4 text-sm">
                        <li class="flex gap-3">
                            <x-heroicon-o-code-bracket class="h-5 w-5 shrink-0 text-brand-gold" aria-hidden="true" />
                            <span class="text-brand-sand/90"><span class="font-semibold text-brand-cream">OpenAPI 3 spec.</span> Sites, deployments, previews, domains, cache purge, usage, and logs in one file—generate clients, mock for tests, drop into Postman or Bruno.</span>
                        </li>
                        <li class="flex gap-3">
                            <x-heroicon-o-key class="h-5 w-5 shrink-0 text-brand-gold" aria-hidden="true" />
                            <span class="text-brand-sand/90"><span class="font-semibold text-brand-cream">Org-scoped tokens.</span> Granular abilities (<code class="text-xs bg-white/10 px-1 rounded">edge.read</code>, <code class="text-xs bg-white/10 px-1 rounded">edge.deploy</code>, <code class="text-xs bg-white/10 px-1 rounded">edge.write</code>), minted in Settings and revocable anytime. The CLI stores them in your OS keyring.</span>
                        </li>
                    </ul>
                </div>
                <div class="mt-10 lg:mt-0 lg:col-span-7">
                    <div class="rounded-2xl border border-white/10 bg-black/40 shadow-xl overflow-hidden">
                        <div class="flex items-center gap-1.5 border-b border-white/10 px-4 py-3">
                            <span class="h-3 w-3 rounded-full bg-white/20"></span>
                            <span class="h-3 w-3 rounded-full bg-white/20"></span>
                            <span class="h-3 w-3 rounded-full bg-white/20"></span>
                            <span class="ml-3 text-xs font-mono text-brand-mist">dply — terminal</span>
                        </div>
                        <div class="px-5 py-5 font-mono text-xs sm:text-sm leading-relaxed text-brand-sand/90 space-y-1">
                            <div><span class="text-brand-mist"># install &amp; sign in via OAuth device flow</span></div>
                            <div><span class="text-brand-gold">$</span> curl -fsSL {{ route('cli.install') }} | bash -s -- --login</div>
                            <div class="text-brand-sage">✓ authenticated · token stored in keyring</div>
                            <div class="pt-3"><span class="text-brand-gold">$</span> dply edge deploy</div>
                            <div class="text-brand-sage">→ release 184 deploying… done in 12s</div>
                            <div class="pt-3"><span class="text-brand-gold">$</span> dply server system-users</div>
                            <div class="text-brand-mist">deploy  web-1  active</div>
                        </div>
                    </div>
                    <p class="mt-4 text-xs text-brand-mist">The CLI is a PHP binary—install via the one-liner and authenticate through the OAuth device flow. Same code path as a GitHub webhook or a button click—no dashboard-only features to give up.</p>
                </div>
            </div>
        </section>

        {{-- Coverage table --}}
        <section id="coverage" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-brand-sand/15 scroll-mt-24">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">No marketing asterisks</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">What's included today</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">A control plane that drives your servers over SSH and provider APIs. Here's the honest state of each area—what's fully supported, what works a bit differently, and what you'd reach for a recipe to do.</p>
                </div>

                <p class="mt-8 flex flex-wrap items-center gap-3 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-1 text-emerald-900"><span class="h-1.5 w-1.5 rounded-full bg-emerald-600" aria-hidden="true"></span> Supported</span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-2.5 py-1 text-amber-950"><span class="h-1.5 w-1.5 rounded-full bg-amber-600" aria-hidden="true"></span> Partial / different model</span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-mist/20 px-2.5 py-1 text-brand-ink"><span class="h-1.5 w-1.5 rounded-full bg-brand-mist" aria-hidden="true"></span> Roadmap / use recipes</span>
                </p>

                <div class="mt-8 overflow-x-auto rounded-2xl border border-brand-ink/10 bg-white/90 shadow-sm">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10 bg-brand-sand/30 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                <th scope="col" class="px-4 py-3 sm:px-6 w-56">Area</th>
                                <th scope="col" class="px-4 py-3 sm:px-6">In {{ config('app.name') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                            @php
                                $coverage = [
                                    ['Server provisioning', 'ok', 'Create or destroy servers via DigitalOcean, Hetzner, Linode, Vultr, UpCloud, Scaleway, Equinix Metal, Fly.io, AWS EC2, and more; attach Custom servers over SSH. The OS and base image stay yours—no resident agent.'],
                                    ['Git deploys &amp; rollbacks', 'ok', 'Git remotes, signed webhooks, deploy hooks, atomic deploys with a <code class="text-xs bg-brand-sand/60 px-1 rounded">releases/</code> directory, and rollback to a prior release.'],
                                    ['PHP / Laravel / Node / static', 'ok', 'Site types for PHP-FPM, Node reverse proxy, and static; Laravel options like scheduler, Octane, and env in the deploy flow.'],
                                    ['Edge &amp; Cloud hosting', 'ok', 'Container apps on DigitalOcean App Platform or AWS App Runner via a unified EdgeBackend. Deploy from git without a server, Nginx config, or OS to maintain. Cloudflare CDN, R2 object storage, and KV included.'],
                                    ['Serverless functions (FaaS)', 'partial', 'HTTP web functions via DigitalOcean Functions—create, deploy, and invoke from the dashboard. Multi-language adapters and package-level features are in progress.'],
                                    ['Managed Realtime', 'ok', 'Pusher-compatible WebSocket relay built on Cloudflare Workers and DigitalOcean. Drop-in for Laravel Echo / Reverb, billed through dply, no relay infra to operate.'],
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
                            @foreach ($coverage as $row)
                                <tr>
                                    <td class="px-4 py-4 sm:px-6 font-medium align-top">{!! $row[0] !!}</td>
                                    <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed align-top">
                                        <span class="{{ $badge[$row[1]][1] }} font-semibold">{{ $badge[$row[1]][0] }}</span> — {!! $row[2] !!}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Security --}}
        <section id="security" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 scroll-mt-24">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">More than a password</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Security &amp; account hygiene</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">Keys and sessions stay under control so the database alone is never enough to impersonate your infrastructure.</p>
                </div>

                <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @php
                        $security = [
                            ['icon' => 'lock-closed', 'title' => 'Encrypted secrets', 'body' => 'Deploy keys, webhook secrets, and env payloads are encrypted at rest.'],
                            ['icon' => 'finger-print', 'title' => 'Two-factor auth', 'body' => 'Turn on 2FA once—it protects login across every org and site you can reach.'],
                            ['icon' => 'shield-check', 'title' => 'Governed API access', 'body' => 'Org-scoped tokens and granular abilities replace long-lived root creds on laptops.'],
                            ['icon' => 'check-badge', 'title' => 'Verified identity', 'body' => 'OAuth sign-in and a verified email; org roles still decide what you can change.'],
                        ];
                    @endphp
                    @foreach ($security as $item)
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest">
                                <x-dynamic-component :component="'heroicon-o-' . $item['icon']" class="h-5 w-5" aria-hidden="true" />
                            </div>
                            <h3 class="mt-5 font-semibold text-brand-ink">{{ $item['title'] }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ $item['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- CTA --}}
        <section class="py-20 px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl mx-auto rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-white via-brand-cream to-brand-sand/30 px-8 py-16 sm:px-14 sm:py-20 text-center shadow-lg shadow-brand-forest/5">
                <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">See it in your account</h2>
                <p class="mt-4 text-lg text-brand-moss max-w-xl mx-auto">Connect a provider, create your first server, and ship a real deploy—on infrastructure you already control. The same flows power everything above.</p>
                <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                    @auth
                        <a href="{{ route('docs.index') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl bg-brand-ink text-brand-cream text-sm font-semibold hover:bg-brand-forest transition-colors shadow-md">Open docs</a>
                        <a href="{{ route('dashboard') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl border-2 border-brand-ink/15 bg-white text-brand-ink text-sm font-semibold hover:border-brand-sage/40 transition-colors">Go to dashboard</a>
                    @else
                        <a href="{{ route('register') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/20 hover:bg-[#d4b24d] transition-colors">Start free trial</a>
                        <a href="{{ route('pricing') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl border-2 border-brand-ink/15 bg-white text-brand-ink text-sm font-semibold hover:border-brand-sage/40 transition-colors">Compare plans</a>
                    @endauth
                </div>
            </div>
        </section>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
