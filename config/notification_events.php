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
    ],
];
