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

];
