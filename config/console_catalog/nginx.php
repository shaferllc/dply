<?php

/**
 * Console catalog — Nginx section.
 */
return [
    'label' => 'Nginx',
    'description' => 'Web server config, reload, and access/error logs.',
    'requires_any_tags' => ['nginx'],
    'entries' => [
        ['command' => 'nginx -v', 'description' => 'Nginx version.'],
        ['command' => 'sudo nginx -t', 'description' => 'Validate config syntax (does not reload).'],
        ['command' => 'sudo systemctl reload nginx', 'description' => 'Graceful reload after a config change.'],
        ['command' => 'sudo systemctl restart nginx', 'description' => 'Hard restart (drops in-flight connections).'],
        ['command' => 'systemctl status nginx --no-pager -n 20', 'description' => 'Service status.'],
        ['command' => 'tail -n 200 /var/log/nginx/error.log', 'description' => 'Recent error log.'],
        ['command' => 'tail -f /var/log/nginx/access.log', 'description' => 'Live tail of access log.'],
        ['command' => 'ls /etc/nginx/sites-enabled/', 'description' => 'Enabled vhosts.'],
        ['command' => 'sudo nginx -T 2>&1 | less', 'description' => 'Dump the full effective config.'],
    ],
];
