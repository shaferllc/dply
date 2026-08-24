<?php

/**
 * Marketing feature set — the basics, one place. Shared by every /features design.
 */
return [
    [
        'icon' => 'server-stack',
        'title' => 'Servers, anywhere',
        'blurb' => 'Provision on DigitalOcean, Hetzner, Vultr, Linode, AWS and more — or connect a box you already own over SSH. No resident agent, no OS lock-in.',
        'tags' => ['12+ providers', 'Bring your own', 'SSH-native'],
    ],
    [
        'icon' => 'rocket-launch',
        'title' => 'Deploy from git',
        'blurb' => 'Push to deploy, or call a signed webhook from your CI. Atomic releases, post-deploy commands, and one-click rollback to any prior release.',
        'tags' => ['Atomic releases', 'Rollback', 'Webhooks'],
    ],
    [
        'icon' => 'globe-alt',
        'title' => 'Sites, Nginx & TLS',
        'blurb' => 'PHP-FPM, Node, or static per site. Vhosts and custom Nginx snippets live with the site, and Let\'s Encrypt certificates renew on their own.',
        'tags' => ['PHP · Node · Static', "Let's Encrypt"],
    ],
    [
        'icon' => 'circle-stack',
        'title' => 'Databases',
        'blurb' => 'Create MySQL, MariaDB, or PostgreSQL databases and users on the server over SSH — or attach a managed cluster when you would rather not run one.',
        'tags' => ['MySQL', 'Postgres', 'Managed clusters'],
    ],
    [
        'icon' => 'clock',
        'title' => 'Cron, queues & workers',
        'blurb' => 'Managed crontab blocks, Supervisor programs, and dedicated Horizon worker pools — processes, balancing, and retries editable from the panel.',
        'tags' => ['Crontab', 'Supervisor', 'Horizon'],
    ],
    [
        'icon' => 'shield-check',
        'title' => 'Firewall & hardening',
        'blurb' => 'Declarative UFW rules with presets and a reviewable history, plus Fail2Ban and security-only unattended upgrades configured at provision time.',
        'tags' => ['UFW', 'Fail2Ban', 'Auto-updates'],
    ],
    [
        'icon' => 'chart-bar',
        'title' => 'Monitoring & backups',
        'blurb' => 'CPU, memory, disk, and uptime checks with history you can line up against deploys — and database plus file backups with retention and a restore path.',
        'tags' => ['Metrics', 'Health checks', 'Restore'],
    ],
    [
        'icon' => 'user-group',
        'title' => 'Teams, audit & API',
        'blurb' => 'Organizations, roles, and invite links keep secrets out of Slack. Every infrastructure change is logged, and org-scoped tokens power the API and CLI.',
        'tags' => ['Roles', 'Audit log', 'API & CLI'],
    ],
];
