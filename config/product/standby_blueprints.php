<?php

/*
|--------------------------------------------------------------------------
| Standby blueprint catalog
|--------------------------------------------------------------------------
|
| Opinionated failover playbooks — not full HA. Each blueprint merges with
| org inventory in StandbyBlueprintPlanner to produce deep-linked steps.
|
*/

return [

    'edge_hybrid_origin' => [
        'title' => 'Edge hybrid origin failover',
        'summary' => 'When a linked Cloud or external SSR origin is down, swap the hybrid Edge origin, purge cache, and verify SSR routes.',
        'doc_slug' => 'edge-delivery',
        'requires' => 'hybrid_edge',
        'steps' => [
            'Confirm Edge static assets still serve from the Worker/CDN path (HTML shell + assets).',
            'Open the linked Cloud origin workspace and check the latest deploy status.',
            'If the origin is unhealthy, promote the last good Cloud deploy or update the standby origin URL on the Edge Delivery panel.',
            'Purge Edge cache for affected hostnames after any origin change.',
            'Re-test SSR routes and API paths that fetch from the origin.',
            'Record failover time, operator, and root cause in the org activity timeline.',
        ],
    ],

    'byo_standby_server' => [
        'title' => 'BYO standby server cutover',
        'summary' => 'Provision or designate a warm standby VM, sync deploy keys and env, then cut traffic with DNS or hostname swap.',
        'doc_slug' => 'create-first-server',
        'requires' => 'byo_sites',
        'steps' => [
            'Identify the production BYO server and sites in scope for failover.',
            'Provision a standby server (same region/provider when possible) or confirm an idle server is ready.',
            'Copy site env vars and deploy recipe settings; attach the same Git remotes and branches.',
            'Run a full deploy on the standby and smoke-test preview hostnames before cutover.',
            'Lower DNS TTL ahead of time if using apex/custom domains (see DNS cutover blueprint).',
            'Cut over: update primary hostname / DNS A or CNAME records to the standby server IP.',
            'Monitor deploy health and roll back DNS if smoke tests fail.',
        ],
    ],

    'dns_cutover' => [
        'title' => 'DNS failover cutover',
        'summary' => 'Lower TTL, validate records at your DNS provider, and point apex or subdomain traffic to a standby target.',
        'doc_slug' => 'edge-domains',
        'requires' => 'custom_domains',
        'steps' => [
            'Inventory custom domains and aliases attached to sites in this org.',
            'Confirm which org credential automates DNS (Server providers → Cloudflare or DigitalOcean).',
            'Lower TTL on apex and critical subdomains at least 24h before a planned cutover.',
            'Prepare standby target: server IP, Cloud live URL, or Edge hostname.',
            'Update A/CNAME records at the provider; wait for propagation (check with dig or online tools).',
            'Verify TLS certificates cover the hostname after DNS moves.',
            'Restore normal TTL once traffic is stable on the standby target.',
        ],
    ],

];
