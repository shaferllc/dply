<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-brand-ink leading-tight">Docs</h2>
    </x-slot>
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <p class="text-brand-moss mb-8">Short guides to get started with dply.</p>

            <h3 class="text-sm font-semibold uppercase tracking-[0.16em] text-brand-mist mb-4">Edge</h3>
            <ul class="space-y-4 mb-10">
                @foreach ([
                    ['slug' => 'edge-overview', 'title' => 'Edge overview', 'desc' => 'What Edge is, static vs hybrid, managed vs BYO Cloudflare, pricing.'],
                    ['slug' => 'edge-fleet', 'title' => 'Edge fleet index', 'desc' => 'Org-wide Edge list, filters, and delete flows.'],
                    ['slug' => 'edge-create', 'title' => 'Create an Edge app', 'desc' => 'Git connect, build detect, hybrid stack, and delivery.'],
                    ['slug' => 'edge-site-overview', 'title' => 'Edge site overview', 'desc' => 'Workspace home tab, hero, delivery and source cards.'],
                    ['slug' => 'edge-deploys', 'title' => 'Edge deploys', 'desc' => 'History, redeploy, ref picker, rollback, and rebuild.'],
                    ['slug' => 'edge-domains', 'title' => 'Edge domains', 'desc' => 'Default hostname, custom domains, and DNS verify.'],
                    ['slug' => 'edge-build', 'title' => 'Edge build', 'desc' => 'Build command, output dir, repo config snapshot, retention.'],
                    ['slug' => 'edge-environment', 'title' => 'Edge environment', 'desc' => 'Production secrets for builds and workers.'],
                    ['slug' => 'edge-deploy-triggers', 'title' => 'Edge deploy triggers', 'desc' => 'Deploy hooks and GitHub auto-deploy webhooks.'],
                    ['slug' => 'edge-delivery', 'title' => 'Edge delivery', 'desc' => 'CDN backend, hybrid SSR origin, image optimization.'],
                    ['slug' => 'edge-routing', 'title' => 'Edge routing', 'desc' => 'Redirects, rewrites, and headers from dply.yaml.'],
                    ['slug' => 'edge-previews', 'title' => 'Edge previews', 'desc' => 'PR previews, preview protection, comment widget.'],
                    ['slug' => 'edge-traffic', 'title' => 'Edge traffic & analytics', 'desc' => 'CDN requests, bandwidth, vitals, and access logs.'],
                    ['slug' => 'edge-billing', 'title' => 'Edge billing & usage', 'desc' => 'Per-site fee and usage month-to-date.'],
                    ['slug' => 'edge-logs', 'title' => 'Edge build & deploy logs', 'desc' => 'CI output and failure reasons per deploy.'],
                    ['slug' => 'edge-danger', 'title' => 'Delete an Edge site', 'desc' => 'Teardown and permanent removal.'],
                    ['slug' => 'edge-preview-comments', 'title' => 'Edge preview comments', 'desc' => 'Review feedback on preview URLs.'],
                    ['slug' => 'deploy-badge', 'title' => 'Deploy to dply badge', 'desc' => 'Markdown snippet for template authors; pre-fill the Edge create form via /deploy.'],
                ] as $edgeDoc)
                    <li>
                        <a href="{{ route('docs.markdown', ['slug' => $edgeDoc['slug']]) }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                            <span class="font-medium text-brand-ink">{{ $edgeDoc['title'] }}</span>
                            <p class="text-sm text-brand-mist mt-1">{{ $edgeDoc['desc'] }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>

            <h3 class="text-sm font-semibold uppercase tracking-[0.16em] text-brand-mist mb-4">Sites &amp; servers</h3>
            <ul class="space-y-4 mb-10">
                <li>
                    <a href="{{ route('docs.connect-provider') }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <span class="font-medium text-brand-ink">Connect a cloud provider</span>
                        <p class="text-sm text-brand-mist mt-1">Get an API token from DigitalOcean or Hetzner and add it under Server providers.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.create-first-server') }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <span class="font-medium text-brand-ink">Create your first server</span>
                        <p class="text-sm text-brand-mist mt-1">Choose provider, region, size, optional setup script; then deploy.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.markdown', ['slug' => 'sites-and-deploy']) }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <span class="font-medium text-brand-ink">Sites, DNS &amp; deploy</span>
                        <p class="text-sm text-brand-mist mt-1">Domains, SSL, zero-downtime vs simple deploy, webhook triggers.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.markdown', ['slug' => 'server-workspace']) }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <span class="font-medium text-brand-ink">Server workspace overview</span>
                        <p class="text-sm text-brand-mist mt-1">Deploy, cron, daemons, firewall, databases, SSH keys, and related areas.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.markdown', ['slug' => 'local-development']) }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <span class="font-medium text-brand-ink">Local development</span>
                        <p class="text-sm text-brand-mist mt-1">Running the app locally for contributors (environment and dependencies).</p>
                    </a>
                </li>
            </ul>

            <h3 class="text-sm font-semibold uppercase tracking-[0.16em] text-brand-mist mb-4">Organization</h3>
            <ul class="space-y-4">
                <li>
                    <a href="{{ route('docs.markdown', ['slug' => 'credentials']) }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <span class="font-medium text-brand-ink">Server providers vs Git</span>
                        <p class="text-sm text-brand-mist mt-1">Where infrastructure tokens live versus Git OAuth for deployments.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <span class="font-medium text-brand-ink">Roles, trial limits, and Pro billing</span>
                        <p class="text-sm text-brand-mist mt-1">Owner, admin, member, deployer; organization-wide trial caps and what changes on Pro.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.markdown', ['slug' => 'billing-and-plans']) }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <span class="font-medium text-brand-ink">Billing &amp; plans</span>
                        <p class="text-sm text-brand-mist mt-1">Invoices, payment method, tax and VAT at a high level.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.markdown', ['slug' => 'source-control']) }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <span class="font-medium text-brand-ink">Source control &amp; deploy flow</span>
                        <p class="text-sm text-brand-mist mt-1">Repos, webhooks, and how deployments run end to end.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.api') }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <span class="font-medium text-brand-ink">HTTP API</span>
                        <p class="text-sm text-brand-mist mt-1">Authenticate with API tokens, base URL, and common endpoints.</p>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</x-app-layout>
