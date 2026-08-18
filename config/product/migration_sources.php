<?php

/*
|--------------------------------------------------------------------------
| Migration landing-page content
|--------------------------------------------------------------------------
|
| Marketing copy for /migrate/{slug} pages. Each entry pairs with an
| existing import wizard in the authed app — the CTA links go straight to
| those URLs, so signed-in operators skip the marketing layer entirely.
|
| Slugs must match the route segment defined in routes/web.php.
|
*/

return [

    'forge' => [
        'name' => 'Laravel Forge',
        'kicker' => 'BYO VMs',
        'tagline' => 'Bring your Forge servers, sites, env, and deploy hooks across — keep your provider relationship and your SSH access.',
        'meta' => 'Move from Laravel Forge to dply in an afternoon. The Forge import wizard brings servers, sites, environment variables, and deploy hooks across without touching your provider or your SSH keys.',
        'headline' => 'From Laravel Forge to dply, in an afternoon',
        'hero' => 'Forge is great at what it does. If you have outgrown the single-lane PHP-VM model — and you want Cloud containers, Edge static, and Serverless functions in the same org without three more subscriptions — the import wizard is built for exactly that handoff.',
        'cta_href' => '/imports/forge',
        'cta_label' => 'Open Forge import',
        'moves' => [
            'Your Forge <strong class="text-brand-ink">servers</strong> (read-only inventory first, then opt-in attach over SSH).',
            'Per-site <strong class="text-brand-ink">domains, env vars, and deploy hooks</strong>.',
            'Recipes and saved commands map to dply <strong class="text-brand-ink">scripts</strong> and <strong class="text-brand-ink">saved commands</strong>.',
            'SSL certificates stay where they are; dply picks up renewals once you cut over DNS.',
        ],
        'stays' => [
            'Your <strong class="text-brand-ink">provider account</strong> (DigitalOcean, Hetzner, AWS, etc.) — dply does not move your VMs.',
            'Existing <strong class="text-brand-ink">SSH keys</strong> on the box are preserved; dply syncs its own deploy key on top.',
            '<strong class="text-brand-ink">DNS cut-over</strong> is yours to schedule when the parity view looks right.',
        ],
        'steps' => [
            ['title' => 'Connect your Forge token', 'body' => 'Paste a read-only Forge API token. The wizard pulls a server + site inventory and shows you exactly what it would import — nothing changes on Forge.'],
            ['title' => 'Pick what to bring', 'body' => 'Tick the servers and sites you want managed in dply. The agent SSH-tests each box, then attaches it to your dply org. Env and deploy hooks come along.'],
            ['title' => 'Cut over when ready', 'body' => 'Deploy from dply, watch the deploy log, then flip DNS on your own schedule. The parity view keeps comparing back to Forge until you turn it off.'],
        ],
        'parity_title' => 'Continuous parity, not a one-way handoff',
        'parity_body' => 'After the import we keep showing drift between Forge and dply for as long as you leave the source connected — env vars, hooks, server membership. Most import wizards stop talking to you the moment they finish; dply keeps the receipt open so cut-over is a decision, not a leap.',
    ],

    'ploi' => [
        'name' => 'Ploi',
        'kicker' => 'BYO VMs',
        'tagline' => 'Same idea as Forge but Ploi-shaped. Import servers, sites, env, and deploy hooks; keep your SSH access and your provider bill.',
        'meta' => 'Move from Ploi to dply in an afternoon. The Ploi import wizard brings servers, sites, env, and deploy hooks across without touching your provider or SSH keys.',
        'headline' => 'From Ploi to dply, in an afternoon',
        'hero' => 'Ploi nails the BYO panel experience. If you want the same SSH-driven model but with Cloud, Edge, and Serverless joining the same org — and one billing relationship across all four — the Ploi wizard ships you there without re-keying your inventory.',
        'cta_href' => '/imports/ploi',
        'cta_label' => 'Open Ploi import',
        'moves' => [
            'Your Ploi <strong class="text-brand-ink">servers</strong> as a read-only inventory, then opt-in attach with deploy-user SSH.',
            'Per-site <strong class="text-brand-ink">domains, environment variables, and deploy hooks</strong>.',
            'Migration progress is tracked per server — you can pause and resume across the afternoon.',
            'Cron and daemon definitions surface in the dply server workspace once attached.',
        ],
        'stays' => [
            'Your <strong class="text-brand-ink">cloud account</strong> and VM billing — dply prices its own work, not your provider invoice.',
            'Existing <strong class="text-brand-ink">authorized keys</strong> on the server stay put; dply adds its deploy key alongside.',
            '<strong class="text-brand-ink">Cut-over timing</strong> is yours; the parity view tells you when nothing important still differs.',
        ],
        'steps' => [
            ['title' => 'Paste a Ploi token', 'body' => 'A read-only token is enough to list your Ploi servers and sites. Nothing changes in Ploi during this step.'],
            ['title' => 'Migrate per server', 'body' => 'Run the migration server-by-server. Each one shows a live progress view — SSH check, site copy, env copy, deploy-hook copy — and a clear pause/resume.'],
            ['title' => 'Verify and cut over', 'body' => 'Deploy from dply, compare against Ploi in the parity view, flip DNS at your pace. Disconnect the Ploi credential when you stop caring about drift.'],
        ],
        'parity_title' => 'Drift detection until you say stop',
        'parity_body' => 'Most migrations end with a shrug — "I think it copied?" The dply parity view keeps the source credential alive after import and shows you exactly which env vars, hooks, or sites differ. Cut over when the diff is empty, not when the wizard finishes.',
    ],

    'vercel' => [
        'name' => 'Vercel',
        'kicker' => 'Edge / static / SSR',
        'tagline' => 'Bring your Vercel projects to dply Edge — Cloudflare Workers + R2 underneath, your framework presets and env on top.',
        'meta' => 'Move from Vercel to dply Edge in an afternoon. Import projects, environment variables, and framework presets to Cloudflare Workers + R2 — and keep an origin Cloud app in the same org for SSR.',
        'headline' => 'From Vercel to dply Edge, in an afternoon',
        'hero' => 'Vercel is a strong edge / SSR product, but the moment you also need an API or a database you start juggling subscriptions. dply Edge runs on Cloudflare Workers + R2, and your SSR origin can be a dply Cloud container in the same org — one vault, one bill, one audit trail across the stack.',
        'cta_href' => '/edge/import',
        'cta_label' => 'Open Edge import',
        'moves' => [
            'Your <strong class="text-brand-ink">Vercel project</strong> (or any Git repo) — we auto-detect Next, Astro, SvelteKit, Remix, Nuxt, and friends.',
            '<strong class="text-brand-ink">Environment variables</strong> from Vercel transfer to the dply env editor as the import completes.',
            'Build settings, output directory, SPA fallback, monorepo root — all detected, all editable after the fact.',
            'SSR repos auto-suggest the <strong class="text-brand-ink">hybrid stack</strong>: Worker static + Cloud origin, provisioned in one click.',
        ],
        'stays' => [
            'Your <strong class="text-brand-ink">Git provider</strong> (GitHub, GitLab, Bitbucket) — dply connects via OAuth, the repo never moves.',
            '<strong class="text-brand-ink">Custom domains</strong> stay on your registrar; you update one DNS record after the first green deploy.',
            'Preview comments, analytics, and traffic split are <strong class="text-brand-ink">opt-in</strong> in dply, not assumed.',
        ],
        'steps' => [
            ['title' => 'Connect Vercel + Git', 'body' => 'Paste a Vercel token for the project list; OAuth your Git provider so dply can pull commits. Pick the projects you want to bring.'],
            ['title' => 'Detect and deploy', 'body' => 'dply auto-detects framework, build command, output dir, and env. Static repos deploy to a Worker + R2; SSR repos offer the hybrid stack with a Cloud origin.'],
            ['title' => 'Point a domain', 'body' => 'Verify a preview URL on the dply testing apex, then update DNS for your custom domain when you are ready. Vercel keeps serving until you flip.'],
        ],
        'parity_title' => 'Edge + origin, same org',
        'parity_body' => 'Vercel makes the edge story easy and the origin story complicated. dply Edge keeps the edge story just as easy — Git push to Worker, previews on every commit — and lets the origin be a Cloud container right beside it. One vault, one audit log, one Stripe customer.',
    ],

    'netlify' => [
        'name' => 'Netlify',
        'kicker' => 'Edge / static',
        'tagline' => 'Import Netlify sites into dply Edge — build settings, redirects, and env vars land in one org.',
        'meta' => 'Move from Netlify to dply Edge in an afternoon. Import sites with netlify.toml, env, and framework detection on Cloudflare Workers + R2.',
        'headline' => 'From Netlify to dply Edge, in an afternoon',
        'hero' => 'Netlify excels at static and Jamstack deploys. dply Edge gives you the same git-push workflow with hybrid SSR and Cloud origins in the same organization when your app outgrows static-only hosting.',
        'cta_href' => '/edge/import',
        'cta_label' => 'Open Edge import',
        'moves' => [
            'Your <strong class="text-brand-ink">Netlify site</strong> build command, publish directory, and framework hints.',
            '<strong class="text-brand-ink">Environment variables</strong> copied into the dply Edge env editor on create.',
            '<strong class="text-brand-ink">Redirects and headers</strong> from netlify.toml inform dply.yaml during import.',
        ],
        'stays' => [
            'Your <strong class="text-brand-ink">Git repository</strong> stays on GitHub, GitLab, or Bitbucket.',
            '<strong class="text-brand-ink">DNS</strong> remains on your registrar until you point records at dply.',
        ],
        'steps' => [
            ['title' => 'Connect Netlify', 'body' => 'Paste a personal access token. Pick the site to import — nothing changes on Netlify until you cut DNS over.'],
            ['title' => 'Prefill Edge create', 'body' => 'dply opens Edge create with repo, branch, build, and env count already filled from the Netlify project.'],
            ['title' => 'Track in parity', 'body' => 'After deploy, the import parity view keeps a receipt of what landed and links back to your Netlify dashboard for comparison.'],
        ],
        'parity_title' => 'Import receipt stays open',
        'parity_body' => 'The Edge import wizard hands off to create, then the parity dashboard records the source provider and project id so you can compare live URLs before you retire Netlify.',
    ],

    'cloudflare-pages' => [
        'name' => 'Cloudflare Pages',
        'kicker' => 'Edge / static',
        'tagline' => 'Bring Cloudflare Pages projects to dply Edge with the same Workers + R2 delivery stack you already know.',
        'meta' => 'Move from Cloudflare Pages to dply Edge. Import build config and env via API token — stay on Cloudflare delivery with dply org controls.',
        'headline' => 'From Cloudflare Pages to dply Edge, in an afternoon',
        'hero' => 'Pages is already on Cloudflare. dply Edge keeps delivery on Workers + R2 but adds org-wide billing, Fleet ops, hybrid origins, and BYO VMs in the same panel.',
        'cta_href' => '/edge/import',
        'cta_label' => 'Open Edge import',
        'moves' => [
            'Your <strong class="text-brand-ink">Pages project</strong> production branch, build command, and output directory.',
            '<strong class="text-brand-ink">Environment variables</strong> (where the API exposes them) transfer on create.',
            'Framework detection for popular static and SSR presets.',
        ],
        'stays' => [
            'Your <strong class="text-brand-ink">Cloudflare account</strong> continues to own DNS and zones until you re-point.',
            'BYO Cloudflare credentials remain optional for delivery — managed dply Edge is the default.',
        ],
        'steps' => [
            ['title' => 'Connect Cloudflare', 'body' => 'Provide an API token with Pages read access and your account id.'],
            ['title' => 'Import + deploy', 'body' => 'Pick a project, review the prefill, and launch the first Edge build from dply.'],
            ['title' => 'Cut over DNS', 'body' => 'When the dply preview URL looks right, update custom hostnames and retire the Pages project.'],
        ],
        'parity_title' => 'Same CDN family, one control plane',
        'parity_body' => 'You are not leaving Cloudflare — you are centralizing deploy history, org RBAC, and multi-engine ops (BYO + Cloud + Edge) in dply while Pages keeps serving until you flip.',
    ],

];
