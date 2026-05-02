<?php

return [
    /**
     * Default group for site files under VM-backed PHP sites (nginx + PHP-FPM on Debian/Ubuntu).
     * Used when resetting ownership to :effective_user:web_group.
     */
    'vm_site_file_web_group' => env('DPLY_VM_SITE_FILE_WEB_GROUP', 'www-data'),

    'workspace_tabs' => [
        'general' => ['label' => 'General'],
        'routing' => ['label' => 'Routing'],
        'dns' => ['label' => 'DNS'],
        'certificates' => ['label' => 'Certificates'],
        'deploy' => ['label' => 'Deploy'],
        'repository' => ['label' => 'Repository'],
        'runtime' => ['label' => 'Runtime'],
        'system-user' => ['label' => 'System user'],
        'laravel-stack' => ['label' => 'Laravel'],
        'environment' => ['label' => 'Environment'],
        'logs' => ['label' => 'Logs'],
        'notifications' => ['label' => 'Notifications'],
        'basic-auth' => ['label' => 'Authentication'],
        'danger' => ['label' => 'Danger zone'],
    ],
];
