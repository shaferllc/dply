<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Features – {{ config('app.name') }}</title>
    <meta name="description" content="Organizations, billing for every site under one plan, personal 2FA and OAuth, cloud credentials, servers, deploy webhooks, and day-two operations—how {{ config('app.name') }} fits together for your team.">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
                <nav class="mt-10 flex flex-wrap justify-center gap-2 text-sm" aria-label="On this page">
                    <a href="#how-it-fits" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">How it fits</a>
                    <a href="#organizations" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Organizations</a>
                    <a href="#credentials" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Credentials</a>
                    <a href="#servers" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Servers</a>
                    <a href="#sites" class="rounded-full border border-brand-ink/15 bg-white/80 px-4 py-2 font-medium text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink transition-colors">Sites &amp; deploy</a>
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

                <div id="plans-and-account" class="mt-12 scroll-mt-24 rounded-2xl border border-brand-gold/35 bg-gradient-to-br from-brand-gold/10 to-brand-sand/20 px-6 py-8 sm:px-10">
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
</body>
</html>
