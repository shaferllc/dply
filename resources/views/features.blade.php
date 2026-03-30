<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Features – {{ config('app.name') }}</title>
    <meta name="description" content="BYO cloud servers with nginx, TLS, PHP, firewall—alongside classic panels like ServerPilot. Plus Git deploys, org vault, CD, IaC-friendly ops, Forge-style transparency, AI-builder complement.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
        {{-- Hero --}}
        <section class="pt-16 pb-12 sm:pt-20 sm:pb-16 px-4 sm:px-6 lg:px-8 border-b border-brand-ink/10">
            <div class="max-w-4xl mx-auto text-center">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">Platform tour</p>
                <h1 class="mt-4 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl">Everything in one operating model</h1>
                <p class="mt-5 text-lg text-brand-moss max-w-2xl mx-auto leading-relaxed">
                    {{ config('app.name') }} connects <strong class="text-brand-ink font-semibold">who</strong> is allowed to work (organizations),
                    <strong class="text-brand-ink font-semibold">what</strong> they can touch (credentials and servers),
                    and <strong class="text-brand-ink font-semibold">how</strong> apps go live (sites, SSL, git deploys, and hooks)—without scattering secrets or SSH config across laptops.
                </p>
                <p class="mt-4 text-base text-brand-moss/90 max-w-2xl mx-auto leading-relaxed">
                    The same <strong class="text-brand-forest font-medium">developer-first</strong> idea as Git-centric platforms (e.g.
                    <a href="https://bohr.io" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">bohr.io</a>):
                    spend energy on product code, not on re-creating deploy plumbing—while <strong class="text-brand-forest font-medium">you keep the servers</strong>.
                </p>
                <p class="mt-3 text-sm text-brand-moss/85 max-w-2xl mx-auto leading-relaxed">
                    If you define cloud resources with <strong class="text-brand-forest font-medium">infrastructure as code</strong> (e.g.
                    <a href="https://www.pulumi.com" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">Pulumi</a>
                    in TypeScript, Python, or Go), {{ config('app.name') }} sits <strong class="text-brand-forest font-medium">next to that workflow</strong>—see the section on platform ops below.
                </p>
                <nav class="mt-10 flex flex-wrap justify-center gap-2 text-sm" aria-label="On this page">
                    <a href="#how-it-fits" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">How it fits</a>
                    <a href="#git-native" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Git-first</a>
                    <a href="#ai-builders" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">AI builders</a>
                    <a href="#platform-ops" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">IaC &amp; ops</a>
                    <a href="#cd-releases" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">CD &amp; releases</a>
                    <a href="#organizations" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Organizations</a>
                    <a href="#credentials" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Credentials</a>
                    <a href="#servers" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Servers</a>
                    <a href="#sites" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Sites &amp; deploy</a>
                    <a href="#forge-style" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Forge-style</a>
                    <a href="#serverpilot" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">ServerPilot</a>
                    <a href="#coverage" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Coverage</a>
                    <a href="#plans-and-account" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Plans &amp; account</a>
                    <a href="#security" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Security</a>
                </nav>
            </div>
        </section>

        {{-- How it fits together --}}
        <section id="how-it-fits" class="py-16 sm:py-24 px-4 sm:px-6 lg:px-8 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl text-center">How it works together</h2>
                <p class="mt-4 text-center text-brand-moss max-w-2xl mx-auto">Data and permissions flow through a single hierarchy—so onboarding a teammate never means forwarding API tokens.</p>

                <ol class="mt-16 grid gap-8 lg:grid-cols-5 lg:gap-4 relative">
                    @php
                        $steps = [
                            ['n' => '1', 'title' => 'Organization', 'body' => 'Every resource belongs to an org. Switch context, invite people, and align billing to the team that owns the infrastructure—one subscription covers every server and every site in that org.'],
                            ['n' => '2', 'title' => 'Credentials', 'body' => 'Cloud API tokens live in the vault, scoped to the org. Members run workflows without copying secrets into local env files.'],
                            ['n' => '3', 'title' => 'Servers', 'body' => 'Provision from supported clouds, or register any box over SSH. One inventory for commands, health checks, databases, cron, processes, and firewall rules.'],
                            ['n' => '4', 'title' => 'Sites', 'body' => 'Map domains to runtimes (PHP, Node, or static). Nginx, TLS, git remotes, env files, and deploy automation stay attached to the server they run on.'],
                            ['n' => '5', 'title' => 'Ship & operate', 'body' => 'Trigger deploys from git hooks or your CI. Run ad-hoc commands, review deployment output, and adjust worker and firewall config from the same console.'],
                        ];
                    @endphp
                    @foreach ($steps as $step)
                        <li class="rounded-2xl border border-brand-ink/10 bg-white/80 p-6 shadow-sm lg:flex lg:flex-col">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand-ink text-brand-cream text-sm font-bold">{{ $step['n'] }}</span>
                            <h3 class="mt-4 text-lg font-semibold text-brand-ink">{{ $step['title'] }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed flex-1">{{ $step['body'] }}</p>
                        </li>
                    @endforeach
                </ol>

                <div class="mt-12 rounded-2xl border border-brand-ink/10 bg-brand-ink text-brand-cream px-6 py-8 sm:px-10">
                    <p class="text-sm font-medium text-brand-sand/90 leading-relaxed max-w-3xl">
                        <span class="text-brand-gold font-semibold">Mental model:</span> the organization is the trust boundary. Credentials never leave it; servers and sites inherit it; every action is tied to a member who is already authenticated into that org.
                    </p>
                </div>
            </div>
        </section>

        {{-- Git-native developer workflow (similar positioning to bohr.io: integrate with Git, automate deploys) --}}
        <section id="git-native" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-gradient-to-b from-white/60 to-brand-sand/20 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">Code more, manage less—on your metal</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Git-first workflows, not a black-box host</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">
                        Platforms like <a href="https://bohr.io" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">bohr.io</a>
                        emphasize GitHub-integrated deploys and a smooth path from repo to production.
                        {{ config('app.name') }} brings that <strong class="text-brand-forest font-medium">same mental model</strong>—OAuth to <strong class="text-brand-forest font-medium">GitHub, GitLab, and Bitbucket</strong> for source control, signed <strong class="text-brand-forest font-medium">deploy webhooks</strong>, and per-environment configuration—while your workloads run on <strong class="text-brand-forest font-medium">servers and credentials you own</strong> (BYO or cloud-provisioned).
                    </p>
                </div>

                <ul class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <li class="rounded-2xl border border-brand-ink/10 bg-white/90 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Same deploys from UI, git, or CI</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Trigger deploys from the dashboard, from a <strong class="text-brand-forest font-medium">signed webhook</strong> after a push, or from your pipeline using <strong class="text-brand-forest font-medium">organization-scoped API tokens</strong>—granular abilities, same operations the app uses.</p>
                    </li>
                    <li class="rounded-2xl border border-brand-ink/10 bg-white/90 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Frontend and backend in one org</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Run <strong class="text-brand-forest font-medium">static</strong> and <strong class="text-brand-forest font-medium">Node</strong> sites next to <strong class="text-brand-forest font-medium">PHP</strong> apps (Laravel and others) on the same inventory—domains, TLS, and env stay next to each site.</p>
                    </li>
                    <li class="rounded-2xl border border-brand-ink/10 bg-white/90 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Secrets that stay in the platform</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Deploy keys, webhook secrets, and env payloads are <strong class="text-brand-forest font-medium">encrypted at rest</strong>; teammates collaborate through the org instead of passing keys in chat. When you need the box, <strong class="text-brand-forest font-medium">SSH</strong> and remote commands are still there.</p>
                    </li>
                </ul>
            </div>
        </section>

        {{-- AI vibe-coding / no-code builders (e.g. YouWare) vs production ops on owned infra --}}
        <section id="ai-builders" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-white/60 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">Vibe coding · then production</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Not an AI website generator—operations for code you own</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">
                        <a href="https://www.youware.com/" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">YouWare</a>
                        and similar products focus on <strong class="text-brand-forest font-medium">“vibe coding”</strong>: chat with AI to spin up landing pages, dashboards, prototypes, internal tools, Figma-to-site flows, and more—often with hosting, credits, and built-in AI APIs so creators can ship fast without touching servers.
                    </p>
                    <p class="mt-4 text-brand-moss leading-relaxed">
                        {{ config('app.name') }} does <strong class="text-brand-forest font-medium">not</strong> replace that experience. We do not generate sites from a prompt or run a no-code app builder.
                        We are the <strong class="text-brand-forest font-medium">ops layer</strong> when you are ready to run <strong class="text-brand-forest font-medium">real repositories</strong> on <strong class="text-brand-forest font-medium">infrastructure you control</strong>: git deploys, TLS, org-scoped secrets, teams, backups, and the same server inventory your engineers SSH into.
                    </p>
                    <p class="mt-4 text-brand-moss leading-relaxed">
                        Many teams use <strong class="text-brand-forest font-medium">both kinds of tool</strong>—a fast AI builder for experiments or marketing surfaces, and {{ config('app.name') }} for the product that needs <strong class="text-brand-forest font-medium">predictable deploys, audit trails, and BYO compliance boundaries</strong>.
                    </p>
                </div>

                <ul class="mt-12 grid gap-6 lg:grid-cols-3">
                    <li class="rounded-2xl border border-brand-ink/10 bg-brand-cream/90 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">YouWare-style: ideate fast</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Describe a page or app in chat, get responsive UI, prototypes, or Figma-driven output—great for velocity before you commit to a repo and runtime.</p>
                    </li>
                    <li class="rounded-2xl border border-brand-ink/10 bg-brand-cream/90 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">{{ config('app.name') }}: operate for real</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Wire <strong class="text-brand-forest font-medium">git</strong>, <strong class="text-brand-forest font-medium">webhooks</strong>, and <strong class="text-brand-forest font-medium">APIs</strong> to servers you pay for; keep <strong class="text-brand-forest font-medium">env and keys</strong> in the org vault; roll out with <strong class="text-brand-forest font-medium">releases and rollback</strong>.</p>
                    </li>
                    <li class="rounded-2xl border border-brand-ink/10 bg-brand-cream/90 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Same company, two speeds</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Landing pages or demos from an AI builder; customer-facing APIs and apps on {{ config('app.name') }}—without pretending one product is the other.</p>
                    </li>
                </ul>
            </div>
        </section>

        {{-- Complement to IaC / platform engineering tools (e.g. Pulumi: define infra in code; dply: operate servers & apps) --}}
        <section id="platform-ops" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-white/50 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">Infrastructure as code · then what?</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Complements IaC platforms like Pulumi</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">
                        <a href="https://www.pulumi.com" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">Pulumi</a>
                        is a <strong class="text-brand-forest font-medium">modern infrastructure-as-code platform</strong>: real languages (TypeScript, Python, Go, .NET, Java, YAML), provider registry, centralized
                        <strong class="text-brand-forest font-medium">secrets and configuration</strong> (e.g. Pulumi ESC), governance and insights, and patterns for
                        <strong class="text-brand-forest font-medium">internal developer platforms</strong>—so teams can define and provision cloud resources as software.
                    </p>
                    <p class="mt-4 text-brand-moss leading-relaxed">
                        {{ config('app.name') }} is <strong class="text-brand-forest font-medium">not</strong> a drop-in replacement for Pulumi or Terraform: we do not compile arbitrary multi-cloud infrastructure graphs from your repo.
                        We are the <strong class="text-brand-forest font-medium">operations control plane</strong> for the servers and sites your team actually runs—whether those VMs or networks were created with Pulumi, by hand, or through our built-in provider integrations.
                    </p>
                </div>

                <ul class="mt-12 grid gap-6 lg:grid-cols-3">
                    <li class="rounded-2xl border border-brand-ink/10 bg-brand-cream/80 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Same automation ethos</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Granular <strong class="text-brand-forest font-medium">HTTP APIs</strong> and org-scoped tokens mirror the “scriptable platform” expectation—CI and services can trigger the <strong class="text-brand-forest font-medium">same deploys and server actions</strong> as the dashboard.</p>
                    </li>
                    <li class="rounded-2xl border border-brand-ink/10 bg-brand-cream/80 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Secrets &amp; governance</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Provider credentials and app secrets live <strong class="text-brand-forest font-medium">encrypted</strong> in the org vault; <strong class="text-brand-forest font-medium">audit trails</strong> record infrastructure actions—aligned with platform engineering discipline, scoped to application delivery.</p>
                    </li>
                    <li class="rounded-2xl border border-brand-ink/10 bg-brand-cream/80 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Typical split of responsibilities</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Use <strong class="text-brand-forest font-medium">Pulumi</strong> (or similar) for durable cloud definitions; use <strong class="text-brand-forest font-medium">{{ config('app.name') }}</strong> for SSH-backed <strong class="text-brand-forest font-medium">sites, TLS, git deploys, databases, cron, firewall</strong>, and team workflows on those machines.</p>
                    </li>
                </ul>
            </div>
        </section>

        {{-- CI vs CD: overlap with Octopus-style release orchestration (narrower scope in dply) --}}
        <section id="cd-releases" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-gradient-to-b from-white/70 to-brand-sand/20 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">CI is not CD</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Deploy &amp; releases — alongside tools like Octopus</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">
                        <a href="https://octopus.com" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">Octopus Deploy</a>
                        is built for <strong class="text-brand-forest font-medium">continuous delivery at scale</strong>: take over after your CI server, orchestrate releases across environments, model runbooks and tenants, target Kubernetes and multi-cloud hosts, and enforce RBAC and compliance—addressing the gap that
                        <strong class="text-brand-forest font-medium">“CI is not CD”</strong> (build pipelines alone are not full delivery).
                    </p>
                    <p class="mt-4 text-brand-moss leading-relaxed">
                        {{ config('app.name') }} covers the <strong class="text-brand-forest font-medium">application deploy side</strong> for <strong class="text-brand-forest font-medium">servers and sites you own</strong>:
                        <strong class="text-brand-forest font-medium">signed webhooks</strong> and <strong class="text-brand-forest font-medium">org-scoped APIs</strong> to trigger deploys,
                        <strong class="text-brand-forest font-medium">deployment history</strong>, <strong class="text-brand-forest font-medium">atomic releases</strong> with <strong class="text-brand-forest font-medium">rollback</strong>,
                        and per-site <strong class="text-brand-forest font-medium">environments</strong> and encrypted env—so your CI (GitHub Actions, Jenkins, GitLab CI, etc.) can build and test while we push to the runtime over SSH.
                    </p>
                    <p class="mt-4 text-brand-moss leading-relaxed">
                        We are <strong class="text-brand-forest font-medium">not</strong> a drop-in replacement for Octopus: there is no <strong class="text-brand-forest font-medium">tenant-per-customer</strong> deployment model, no <strong class="text-brand-forest font-medium">first-class Kubernetes control plane</strong>, and no <strong class="text-brand-forest font-medium">runbook</strong> product as rich as theirs.
                        If you need enterprise-grade release orchestration across many clusters and teams, Octopus remains in that category; if you need a <strong class="text-brand-forest font-medium">focused BYO-server path</strong> from git to production with a clear audit trail in the org, that is {{ config('app.name') }}.
                    </p>
                </div>

                <ul class="mt-12 grid gap-6 lg:grid-cols-3">
                    <li class="rounded-2xl border border-brand-ink/10 bg-white/95 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">CI builds, {{ config('app.name') }} deploys</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Keep CI where it belongs—compile, test, static analysis—then trigger a deploy with the same <strong class="text-brand-forest font-medium">webhook or API</strong> your pipeline would call manually.</p>
                    </li>
                    <li class="rounded-2xl border border-brand-ink/10 bg-white/95 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Releases you can roll back</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Track <strong class="text-brand-forest font-medium">deployments</strong> and flip the <strong class="text-brand-forest font-medium">current</strong> symlink to a prior release when something goes wrong—without inventing glue scripts per app.</p>
                    </li>
                    <li class="rounded-2xl border border-brand-ink/10 bg-white/95 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Octopus-scale CD</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">For <strong class="text-brand-forest font-medium">multi-team, multi-cluster, compliance-heavy</strong> promotion pipelines, products like <a href="https://octopus.com" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">Octopus</a> are purpose-built; {{ config('app.name') }} stays focused on <strong class="text-brand-forest font-medium">your org’s servers and sites</strong> in one control plane.</p>
                    </li>
                </ul>
            </div>
        </section>

        {{-- Organizations --}}
        <section id="organizations" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-white/40 scroll-mt-24">
            <div class="max-w-6xl mx-auto lg:grid lg:grid-cols-12 lg:gap-12 lg:items-start">
                <div class="lg:col-span-4">
                    <h2 class="text-3xl font-bold tracking-tight text-brand-ink">Organizations &amp; people</h2>
                    <p class="mt-3 text-brand-moss">Multi-tenant by design—your production org stays separate from personal experiments.</p>
                </div>
                <ul class="mt-10 lg:mt-0 lg:col-span-8 space-y-6">
                    <li class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage font-semibold text-sm">A</span>
                        <div>
                            <h3 class="font-semibold text-brand-ink">Org-scoped data</h3>
                            <p class="mt-1 text-sm text-brand-moss leading-relaxed">Servers, sites, credentials, and billing roll up under the active organization. Switch orgs from the app when you belong to more than one.</p>
                        </div>
                    </li>
                    <li class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage font-semibold text-sm">B</span>
                        <div>
                            <h3 class="font-semibold text-brand-ink">Invitations</h3>
                            <p class="mt-1 text-sm text-brand-moss leading-relaxed">Bring teammates in through secure invite links so access is granted in-app—not over Slack with raw tokens.</p>
                        </div>
                    </li>
                    <li class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage font-semibold text-sm">C</span>
                        <div>
                            <h3 class="font-semibold text-brand-ink">Billing per organization</h3>
                            <p class="mt-1 text-sm text-brand-moss leading-relaxed">Plans attach to the org. Subscription limits apply <strong class="text-brand-forest font-medium">organization-wide</strong>—every server and <strong class="text-brand-forest font-medium">every site</strong> under that org shares the same plan. There is no separate per-site product line on your invoice.</p>
                        </div>
                    </li>
                    <li class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage font-semibold text-sm">D</span>
                        <div>
                            <h3 class="font-semibold text-brand-ink">Activity visibility</h3>
                            <p class="mt-1 text-sm text-brand-moss leading-relaxed">Review recent organization audit entries—who did what—so changes to infrastructure are easier to trace.</p>
                        </div>
                    </li>
                    <li class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage font-semibold text-sm">E</span>
                        <div>
                            <h3 class="font-semibold text-brand-ink">Your account follows you</h3>
                            <p class="mt-1 text-sm text-brand-moss leading-relaxed">Profile, password, verified email, <strong class="text-brand-forest font-medium">two-factor authentication</strong>, and <strong class="text-brand-forest font-medium">OAuth</strong> sign-in (e.g. GitHub, GitLab, Bitbucket when enabled) live on <strong class="text-brand-forest font-medium">your user</strong>—not on each organization or site. The same settings apply everywhere you have access.</p>
                        </div>
                    </li>
                </ul>

                {{-- Must span full grid width: without col-span-12 this sat in column 1 only (narrow strip). --}}
                <div id="plans-and-account" class="mt-12 w-full min-w-0 lg:col-span-12 scroll-mt-24 rounded-2xl border border-brand-gold/35 bg-gradient-to-br from-brand-gold/10 to-brand-sand/20 px-6 py-8 sm:px-10">
                    <h3 class="text-lg font-semibold text-brand-ink">Plans cover the whole organization</h3>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed max-w-3xl">Usage limits we enforce from your plan (for example, how many <strong class="text-brand-forest font-medium">servers</strong> you can connect, and how many <strong class="text-brand-forest font-medium">sites</strong> the org may create on those servers before upgrading to Pro) are counted for the <strong class="text-brand-forest font-medium">entire org</strong>. Billing is not split per application or hostname.</p>
                    <h3 class="mt-8 text-lg font-semibold text-brand-ink">Profile, 2FA, and OAuth are personal</h3>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed max-w-3xl">You sign in as a person. Hardening your account with 2FA, linking an OAuth provider, or updating your profile applies to <strong class="text-brand-forest font-medium">all organizations</strong> you belong to and <strong class="text-brand-forest font-medium">every site</strong> you can reach through those memberships—without reconfiguring security per team.</p>
                </div>
            </div>
        </section>

        {{-- Credentials --}}
        <section id="credentials" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Credentials &amp; clouds</h2>
                <p class="mt-4 text-brand-moss max-w-3xl">Connect the APIs that create and manage infrastructure. Tokens are validated when possible, then stored encrypted—your team uses them through {{ config('app.name') }}, not from plaintext notes.</p>

                <div class="mt-12 grid gap-8 lg:grid-cols-2">
                    <div class="rounded-2xl border border-brand-ink/10 bg-white/80 p-8 shadow-sm">
                        <h3 class="text-lg font-semibold text-brand-ink flex items-center gap-2">
                            <span class="h-2 w-2 rounded-full bg-brand-gold" aria-hidden="true"></span>
                            Full provisioning support
                        </h3>
                        <p class="mt-3 text-sm text-brand-moss leading-relaxed">Create and tear down compute from the panel (where the integration is complete), including:</p>
                        <ul class="mt-4 space-y-2 text-sm text-brand-moss">
                            <li class="flex gap-2"><span class="text-brand-sage font-bold">·</span> DigitalOcean, Hetzner, Linode, Vultr, UpCloud, Scaleway</li>
                            <li class="flex gap-2"><span class="text-brand-sage font-bold">·</span> Equinix Metal, Akamai (Linode), Fly.io, AWS EC2</li>
                        </ul>
                    </div>
                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-8">
                        <h3 class="text-lg font-semibold text-brand-ink">Custom &amp; roadmap providers</h3>
                        <p class="mt-3 text-sm text-brand-moss leading-relaxed">Attach <strong class="text-brand-forest font-medium">any server with SSH</strong> when you already have hardware or a provider we have not wired for provisioning yet.</p>
                        <p class="mt-4 text-sm text-brand-moss leading-relaxed">Additional provider accounts (e.g. Render, Railway, GCP, Azure) can be stored as credentials for future workflows as integrations expand—your labels and vaulting model stay the same.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- Servers --}}
        <section id="servers" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-white/40 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Servers &amp; day-two operations</h2>
                <p class="mt-4 text-brand-moss max-w-3xl">After a machine exists—cloud or custom—the server record becomes your control plane: run commands, declare dependencies, and keep access tidy.</p>

                <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/90 p-6">
                        <h3 class="font-semibold text-brand-ink">Remote execution</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Run shell commands from the dashboard over SSH—ideal for quick fixes without distributing keys to every laptop.</p>
                    </article>
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/90 p-6">
                        <h3 class="font-semibold text-brand-ink">Health checks</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Point at an HTTP endpoint and let the platform track whether your service answers—so status lives next to the server, not in a separate monitor.</p>
                    </article>
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/90 p-6">
                        <h3 class="font-semibold text-brand-ink">Databases</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Record database connections your apps rely on (engines like MySQL), kept alongside the server that hosts them.</p>
                    </article>
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/90 p-6">
                        <h3 class="font-semibold text-brand-ink">Cron &amp; workers</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Model scheduled jobs and Supervisor programs (queues, workers) so long-running processes are documented and reproducible.</p>
                    </article>
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/90 p-6">
                        <h3 class="font-semibold text-brand-ink">Firewall rules</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Open or restrict ports with intent—pair network policy with the same server your team already uses for deploys.</p>
                    </article>
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/90 p-6">
                        <h3 class="font-semibold text-brand-ink">SSH keys &amp; recipes</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Manage authorized keys centrally and run setup scripts (“recipes”) when you need a repeatable bootstrap beyond the default image.</p>
                    </article>
                </div>
            </div>
        </section>

        {{-- Sites --}}
        <section id="sites" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 scroll-mt-24">
            <div class="max-w-6xl mx-auto lg:grid lg:grid-cols-12 lg:gap-12">
                <div class="lg:col-span-5">
                    <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Sites, TLS &amp; deploys</h2>
                    <p class="mt-4 text-brand-moss leading-relaxed">A <strong class="text-brand-forest font-medium">site</strong> is how traffic reaches your code: hostname, runtime, document root, and deployment settings—all bound to a parent server.</p>
                </div>
                <div class="mt-10 lg:mt-0 lg:col-span-7 space-y-6">
                    <div class="rounded-2xl border border-brand-ink/10 bg-white/80 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Runtimes</h3>
                        <p class="mt-2 text-sm text-brand-moss">PHP (PHP-FPM), Node behind a reverse proxy, or static/HTML—pick the stack that matches the app.</p>
                    </div>
                    <div class="rounded-2xl border border-brand-ink/10 bg-white/80 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Nginx &amp; SSL</h3>
                        <p class="mt-2 text-sm text-brand-moss">Provision vhosts and track certificate status so HTTPS is part of the site lifecycle, not a weekend chore.</p>
                    </div>
                    <div class="rounded-2xl border border-brand-ink/10 bg-white/80 p-6 shadow-sm">
                        <h3 class="font-semibold text-brand-ink">Git &amp; webhooks</h3>
                        <p class="mt-2 text-sm text-brand-moss">Wire a repository and branch, keep deploy keys in the vault, and trigger builds from git push via a signed webhook—CI can call the same hook when you are ready.</p>
                    </div>
                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-forest/5 p-6">
                        <h3 class="font-semibold text-brand-ink">Laravel-friendly options</h3>
                        <p class="mt-2 text-sm text-brand-moss">When the app needs it: Octane ports, scheduler toggles, per-environment <code class="text-xs bg-brand-sand/50 px-1 rounded">.env</code> content, post-deploy commands, release retention, and extra Nginx snippets—configured next to the site, not scattered across playbooks.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- Positioning vs Laravel Forge–style panels; reference Serversinc comparison narrative --}}
        <section id="forge-style" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-gradient-to-b from-brand-sand/25 to-white/80 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Transparency vs Forge-style panels</h2>
                <p class="mt-4 text-brand-moss max-w-3xl leading-relaxed">
                    <strong class="text-brand-forest font-medium">Laravel Forge</strong>-style products make Laravel deploys easy but are sometimes criticized for feeling like a
                    <strong class="text-brand-forest font-medium">black box</strong> when you need non-standard stacks or deeper access.
                    Alternatives such as
                    <a href="https://serversinc.io/compare/laravel-forge/" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">Serversinc (vs Forge)</a>
                    emphasize <strong class="text-brand-forest font-medium">Docker per app</strong>, <strong class="text-brand-forest font-medium">flat platform pricing</strong>, and
                    <strong class="text-brand-forest font-medium">your own VPS</strong>—trading Forge’s opinions for containers and control.
                </p>
                <p class="mt-4 text-brand-moss max-w-3xl leading-relaxed">
                    {{ config('app.name') }} goes after the <strong class="text-brand-forest font-medium">same goals</strong>—<strong class="text-brand-forest font-medium">full control</strong>,
                    <strong class="text-brand-forest font-medium">predictable org billing</strong> for the product, and <strong class="text-brand-forest font-medium">infrastructure you pay providers for directly</strong>—with a
                    <strong class="text-brand-forest font-medium">VM-first</strong> model: <strong class="text-brand-forest font-medium">Nginx, PHP-FPM, Node, and static</strong> sites on the server over SSH, plus git deploys, env management, and optional extra config.
                    We do <strong class="text-brand-forest font-medium">not</strong> require every app to be packaged as a Docker image; if you standardize on Compose or other tooling on the box, you can still drive it through
                    <strong class="text-brand-forest font-medium">SSH and automation</strong>—first-class flows are <strong class="text-brand-forest font-medium">traditional stack + releases</strong>, not orchestration inside the panel.
                </p>

                <div class="mt-10 overflow-x-auto rounded-2xl border border-brand-ink/10 bg-white/95 shadow-sm">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10 bg-brand-sand/30 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                <th scope="col" class="px-4 py-3 sm:px-6">What teams ask for</th>
                                <th scope="col" class="px-4 py-3 sm:px-6">In {{ config('app.name') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Escape the black box</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><strong class="text-brand-forest font-medium">SSH</strong>, server <strong class="text-brand-forest font-medium">recipes</strong>, <strong class="text-brand-forest font-medium">Nginx snippets</strong>, and explicit paths—so tuning is visible, not hidden.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Predictable platform cost</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed">Subscription is <strong class="text-brand-forest font-medium">per organization</strong> and covers <strong class="text-brand-forest font-medium">every server and site</strong> in that org under plan limits; you pay clouds or VPS vendors separately—similar “platform + your infra” clarity to flat-fee comparisons like <a href="https://serversinc.io/compare/laravel-forge/" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">Serversinc’s Forge page</a>.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Not only Laravel</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><strong class="text-brand-forest font-medium">PHP</strong>, <strong class="text-brand-forest font-medium">Node</strong>, and <strong class="text-brand-forest font-medium">static</strong> site types; Laravel-first conveniences (scheduler, Octane, env) without locking the whole org to one framework.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Docker-first (e.g. Serversinc)</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><strong class="text-brand-forest font-medium">Optional</strong> on your server—{{ config('app.name') }} does not mandate a container image per app. Prefer Docker everywhere? Run it on the VM and use the panel for <strong class="text-brand-forest font-medium">coordination, secrets, and deploy triggers</strong>, or pair with a container-centric host.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Classic BYO control panels: ServerPilot-style any-provider hosting --}}
        <section id="serverpilot" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-white/70 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Same lane as ServerPilot: your cloud, your root</h2>
                <p class="mt-4 text-brand-moss max-w-3xl leading-relaxed">
                    <a href="https://serverpilot.io" class="font-semibold text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:decoration-brand-forest">ServerPilot</a>
                    popularized <strong class="text-brand-forest font-medium">bring-your-own-server</strong> management: connect VPS from any provider, keep
                    <strong class="text-brand-forest font-medium">root access</strong>, run <strong class="text-brand-forest font-medium">apps</strong> with
                    <strong class="text-brand-forest font-medium">Let’s Encrypt SSL</strong>, multiple <strong class="text-brand-forest font-medium">PHP</strong> versions,
                    <strong class="text-brand-forest font-medium">Nginx</strong> (and Apache where offered), <strong class="text-brand-forest font-medium">firewall</strong> defaults,
                    <strong class="text-brand-forest font-medium">monitoring dashboards</strong>, and <strong class="text-brand-forest font-medium">migrations</strong> between servers—plus a dashboard/API so you are not stuck in a single vendor’s “managed” cage.
                </p>
                <p class="mt-4 text-brand-moss max-w-3xl leading-relaxed">
                    {{ config('app.name') }} shares that <strong class="text-brand-forest font-medium">core promise</strong>: <strong class="text-brand-forest font-medium">your servers</strong>, <strong class="text-brand-forest font-medium">your providers</strong>, and a control plane for
                    <strong class="text-brand-forest font-medium">sites, TLS, PHP-FPM, Node, static assets, databases, cron, and firewall rules</strong>.
                    We drive configuration over <strong class="text-brand-forest font-medium">SSH</strong> (and cloud APIs when you provision through us)—we do not claim the same
                    <strong class="text-brand-forest font-medium">agent architecture</strong> or <strong class="text-brand-forest font-medium">Apache+.htaccess</strong> stack ServerPilot markets; advanced
                    <strong class="text-brand-forest font-medium">host metrics dashboards</strong> and <strong class="text-brand-forest font-medium">one-click cross-server migrations</strong> are
                    <strong class="text-brand-forest font-medium">not</strong> first-class products here today—use health checks, backups, and manual or scripted moves until dedicated flows exist.
                </p>
                <p class="mt-4 text-brand-moss max-w-3xl leading-relaxed">
                    Where we often go <strong class="text-brand-forest font-medium">further</strong> for product teams: <strong class="text-brand-forest font-medium">git-native deploys</strong>, <strong class="text-brand-forest font-medium">atomic releases</strong>,
                    <strong class="text-brand-forest font-medium">organization RBAC</strong>, <strong class="text-brand-forest font-medium">audit logs</strong>, and <strong class="text-brand-forest font-medium">granular HTTP APIs</strong>—closer to a platform engineering surface than a PHP-only panel.
                </p>

                <div class="mt-10 overflow-x-auto rounded-2xl border border-brand-ink/10 bg-brand-cream/50 shadow-sm">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10 bg-brand-sand/30 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                <th scope="col" class="px-4 py-3 sm:px-6">ServerPilot-style theme</th>
                                <th scope="col" class="px-4 py-3 sm:px-6">In {{ config('app.name') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Any provider · root</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-800 font-semibold">Supported</span> — cloud provisioning integrations plus <strong class="text-brand-forest font-medium">Custom</strong> servers with SSH; you retain provider access and keys in the org vault.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Apps · SSL · PHP</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-800 font-semibold">Supported</span> — sites, Certbot/Let’s Encrypt, per-site PHP and paths; <strong class="text-brand-forest font-medium">Node</strong> and <strong class="text-brand-forest font-medium">static</strong> in addition to PHP.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Nginx · performance stack</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-800 font-semibold">Supported</span> — Nginx vhosts and optional snippets; no bundled <strong class="text-brand-forest font-medium">Apache front door</strong> for <code class="text-xs bg-brand-sand/60 px-1 rounded">.htaccess</code>—tune via Nginx or server recipes if you need Apache semantics.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Firewall · hardening narrative</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-800 font-semibold">Partial</span> — declarative <strong class="text-brand-forest font-medium">UFW</strong> rules from the panel; OS auto-updates and “no exposed management ports” are <strong class="text-brand-forest font-medium">your image/provider</strong> unless you encode them in bootstrap scripts.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Metrics dashboards</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-amber-800 font-semibold">Partial</span> — HTTP <strong class="text-brand-forest font-medium">health checks</strong> and status; not the same as ServerPilot-style <strong class="text-brand-forest font-medium">full host metrics UI</strong>.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Migrations between servers</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-amber-800 font-semibold">Operational</span> — use <strong class="text-brand-forest font-medium">SSH, backups, and deploys</strong>; no dedicated “move app wizard” like some classic panels advertise.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">WordPress one-click</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-amber-800 font-semibold">Partial</span> — run WordPress as a <strong class="text-brand-forest font-medium">PHP site</strong>; no WP-specific installer in the panel today.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Coverage vs common server panels (categories aligned with e.g. SiteKit features) --}}
        <section id="coverage" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-brand-sand/15 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Coverage vs panels like SiteKit</h2>
                <p class="mt-4 text-brand-moss max-w-3xl leading-relaxed">
                    {{ config('app.name') }} is a control plane that drives your servers over SSH and provider APIs—similar in scope to panels that advertise a full LEMP/LAMP stack, Git deploys, and security tooling.
                    The categories below mirror what you see on open-source server panels such as
                    <a href="https://sitekit.dev/features" class="font-semibold text-brand-forest underline decoration-brand-sage/50 underline-offset-2 hover:decoration-brand-forest">SiteKit</a>,
                    so you can compare feature-for-feature. We do not ship a resident agent or a single-line OS bootstrap; instead we integrate with clouds you already use or any box you can SSH into.
                </p>

                <p class="mt-6 flex flex-wrap items-center gap-3 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-1 text-emerald-900"><span class="h-1.5 w-1.5 rounded-full bg-emerald-600" aria-hidden="true"></span> Supported</span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-2.5 py-1 text-amber-950"><span class="h-1.5 w-1.5 rounded-full bg-amber-600" aria-hidden="true"></span> Partial / different model</span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-mist/20 px-2.5 py-1 text-brand-ink"><span class="h-1.5 w-1.5 rounded-full bg-brand-mist" aria-hidden="true"></span> Roadmap / use recipes</span>
                </p>

                <div class="mt-10 overflow-x-auto rounded-2xl border border-brand-ink/10 bg-white/90 shadow-sm">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10 bg-brand-sand/30 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                <th scope="col" class="px-4 py-3 sm:px-6">Area</th>
                                <th scope="col" class="px-4 py-3 sm:px-6">In {{ config('app.name') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Server provisioning</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — create or destroy servers via DigitalOcean, Hetzner, Linode, Vultr, UpCloud, Scaleway, Equinix Metal, Fly.io, AWS EC2, and more; attach <strong class="text-brand-forest font-medium">Custom</strong> servers with SSH. <span class="text-amber-800 font-semibold">Different</span> — we do not install a lightweight agent or run a curl&nbsp;|&nbsp;bash full-stack bootstrap; the OS and packages are yours or your cloud image.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Git deployments &amp; rollbacks</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — git remotes, signed webhooks, deploy hooks, atomic deploys with <code class="text-xs bg-brand-sand/60 px-1 rounded">releases/</code> and rollback to a prior release.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">PHP / Laravel / Node / static</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — site types for PHP-FPM, Node reverse proxy, and static; Laravel-oriented options (scheduler, Octane, env, caches in deploy scripts).</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">WordPress</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-amber-800 font-semibold">Partial</span> — deploy PHP apps and custom scripts; there is no dedicated WordPress provisioning wizard or WP-CLI panel in BYO today. Treat WordPress like any PHP site or automate with deploy scripts.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Databases (MySQL, MariaDB, PostgreSQL)</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — create databases and users on the server over SSH (MySQL/MariaDB and PostgreSQL paths in provisioning).</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Redis</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-brand-ink/80 font-semibold">Roadmap / ops</span> — install and configure Redis on the server outside the dedicated DB wizard, or encode it in server recipes.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">SSL (Let’s Encrypt)</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — Certbot over SSH for site domains; renewal follows your server’s certbot setup.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Firewall (UFW)</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — declarative rules per server synced to <code class="text-xs bg-brand-sand/60 px-1 rounded">ufw allow</code> patterns.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Cron &amp; Supervisor (workers)</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — managed crontab blocks and Supervisor programs tied to servers and sites.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Environment variables</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — per-site environment rows stored in the app (encrypted at rest) and applied in deploy flows.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Monitoring (CPU / RAM / disk)</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-amber-800 font-semibold">Partial</span> — HTTP health checks and server status in the control plane; not a bundled host metrics agent with live graphs like a full APM stack.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Fail2Ban, unattended upgrades, hardening</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-amber-800 font-semibold">Partial</span> — TLS and Nginx configuration from the panel; OS-level hardening (Fail2Ban, unattended-upgrades) is left to your image or run via remote commands/recipes.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">SSH keys</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — account SSH keys with optional deploy to <code class="text-xs bg-brand-sand/60 px-1 rounded">authorized_keys</code>, plus per-server key records.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Backups</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — backup configuration for files and databases (see Backups in the app).</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Teams &amp; audit</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-emerald-700 font-semibold">Supported</span> — organizations, teams, invitations, roles, and audit log entries for infrastructure actions.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-4 sm:px-6 font-medium align-top">Alerts (email, Slack, webhooks)</td>
                                <td class="px-4 py-4 sm:px-6 text-brand-moss leading-relaxed"><span class="text-amber-800 font-semibold">Partial</span> — notification channels and events exist; wire the channels your org uses so deploy and uptime events reach the right place.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Security --}}
        <section id="security" class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-brand-ink text-brand-cream scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">Security &amp; account hygiene</h2>
                <p class="mt-4 text-brand-sand/85 max-w-3xl">Enterprise-style products need more than a password—here is how {{ config('app.name') }} keeps keys and sessions under control.</p>
                <ul class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-2">
                    <li class="rounded-xl border border-white/10 bg-white/5 p-6">
                        <h3 class="font-semibold text-brand-cream">Encrypted secrets</h3>
                        <p class="mt-2 text-sm text-brand-mist leading-relaxed">Sensitive fields (deploy keys, webhook secrets, env payloads) are encrypted at rest so the database alone is never enough to impersonate your infrastructure.</p>
                    </li>
                    <li class="rounded-xl border border-white/10 bg-white/5 p-6">
                        <h3 class="font-semibold text-brand-cream">Two-factor authentication</h3>
                        <p class="mt-2 text-sm text-brand-mist leading-relaxed">Turn on 2FA once in your profile—it protects your login across <strong class="text-brand-cream font-medium">every organization and site</strong> you can access, not just one workspace.</p>
                    </li>
                    <li class="rounded-xl border border-white/10 bg-white/5 p-6">
                        <h3 class="font-semibold text-brand-cream">OAuth sign-in</h3>
                        <p class="mt-2 text-sm text-brand-mist leading-relaxed">Link GitHub, GitLab, or Bitbucket (when your app enables them) to your user account. The same identity signs you in everywhere; <strong class="text-brand-cream font-medium">org roles</strong> still decide what you are allowed to change.</p>
                    </li>
                    <li class="rounded-xl border border-white/10 bg-white/5 p-6">
                        <h3 class="font-semibold text-brand-cream">Verified email &amp; profile</h3>
                        <p class="mt-2 text-sm text-brand-mist leading-relaxed">A verified address is required for dashboard access. Name and profile settings live on your user—they do not reset when you switch org context.</p>
                    </li>
                </ul>
            </div>
        </section>

        {{-- Docs CTA --}}
        <section class="py-20 px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-white via-brand-cream to-brand-sand/30 px-8 py-14 shadow-lg shadow-brand-forest/5">
                <h2 class="text-2xl font-bold tracking-tight text-brand-ink sm:text-3xl">See it in your account</h2>
                <p class="mt-3 text-brand-moss">Guided docs walk through connecting a provider and creating your first server. The same flows power everything above.</p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    @auth
                        <a href="{{ route('docs.index') }}" class="inline-flex items-center px-6 py-3 rounded-xl bg-brand-ink text-brand-cream text-sm font-semibold hover:bg-brand-forest transition-colors shadow-md">Open docs</a>
                        <a href="{{ route('dashboard') }}" class="inline-flex items-center px-6 py-3 rounded-xl border-2 border-brand-ink/15 bg-white text-brand-ink text-sm font-semibold hover:border-brand-sage/40 transition-colors">Dashboard</a>
                    @else
                        <a href="{{ route('register') }}" class="inline-flex items-center px-6 py-3 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/20 hover:bg-[#d4b24d] transition-colors">Create free account</a>
                        <a href="{{ route('pricing') }}" class="inline-flex items-center px-6 py-3 rounded-xl border-2 border-brand-ink/15 bg-white text-brand-ink text-sm font-semibold hover:border-brand-sage/40 transition-colors">Compare plans</a>
                    @endauth
                </div>
            </div>
        </section>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
