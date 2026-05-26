<?php

/**
 * Keys used by notification subscriptions (channel + target + event).
 * Prefix determines compatible target: server.* → Server, site.* / backup.* → Site.
 *
 * Per-systemd-unit keys (server target) are created from the Services workspace; they match
 * {@see ServerSystemdServiceNotificationKeys::isValidDynamicEventKey} and are
 * accepted by bulk-assign validation even though they are not listed below.
 */
return [
    'categories' => [
        'server' => [
            'label' => 'Server notifications',
            'events' => [
                'server.automatic_updates' => 'Automatic updates',
                'server.ssh_login' => 'SSH login',
                'server.insights_alerts' => 'Insights alerts',
                'server.monitoring' => 'Server monitoring',
                'server.container_launch.completed' => 'Container app deploy ready',
                'server.container_launch.failed' => 'Container app deploy failed (action required)',
                'server.provisioned' => 'Server provisioned',
                'server.provision_failed' => 'Server provisioning failed (action required)',
            ],
        ],
        'site' => [
            'label' => 'Site notifications',
            'events' => [
                'site.deployments' => 'Deployments & failing deployments',
                'site.deployment_started' => 'Deployment started',
                'site.uptime' => 'Site uptime monitoring',
            ],
        ],
        'edge' => [
            'label' => 'Edge notifications',
            'events' => [
                'edge.deploy.succeeded' => 'Edge deploy succeeded',
                'edge.deploy.failed' => 'Edge deploy failed (action required)',
                'edge.domain.verified' => 'Custom domain verified',
                'edge.domain.failing' => 'Custom domain verification failing (action required)',
                'edge.usage.over_budget' => 'Edge usage over budget (action required)',
                'edge.rum.breach' => 'Real-user metric threshold breached (action required)',
            ],
        ],
        'backup' => [
            'label' => 'Backup notifications',
            'events' => [
                'backup.database' => 'Database backups',
                'backup.site_files' => 'Site file backups',
            ],
        ],
        'project' => [
            'label' => 'Project notifications',
            'events' => [
                'project.deployments' => 'Project deploy batches',
                'project.health' => 'Project health alerts',
                'project.activity' => 'Project activity summaries',
            ],
        ],
        'import' => [
            'label' => 'Import & migration notifications',
            'events' => [
                'import.migration.cutover_ready' => 'Migration ready for cutover (action required)',
                'import.migration.step_failed' => 'Migration step failed (action required)',
                'import.migration.cutover_complete' => 'Migration cutover complete',
                'import.migration.aborted' => 'Migration aborted',
                'import.migration.paused_nudge' => 'Migration paused — auto-revoke in <96h (action required)',
            ],
        ],
    ],
];
