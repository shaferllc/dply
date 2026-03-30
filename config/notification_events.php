<?php

/**
 * Keys used by notification subscriptions (channel + target + event).
 * Prefix determines compatible target: server.* → Server, site.* / backup.* → Site.
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
    ],
];
