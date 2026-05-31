<?php

return [
    /**
     * Default group for site files under VM-backed PHP sites (nginx + PHP-FPM on Debian/Ubuntu).
     * Used when resetting ownership to :effective_user:web_group.
     */
    'vm_site_file_web_group' => env('DPLY_VM_SITE_FILE_WEB_GROUP', 'www-data'),

    'workspace_tabs' => [
        'general' => ['label' => 'General'],
        'settings' => ['label' => 'Settings'],
        'routing' => ['label' => 'Routing'],
        'dns' => ['label' => 'DNS'],
        'certificates' => ['label' => 'Certificates'],
        'deploy' => ['label' => 'Deploy'],
        'repository' => ['label' => 'Repository'],
        'runtime' => ['label' => 'Runtime'],
        'system-user' => ['label' => 'System user'],
        'laravel-stack' => ['label' => 'Laravel'],
        'rails-stack' => ['label' => 'Rails'],
        'wordpress' => ['label' => 'WordPress'],
        'environment' => ['label' => 'Environment'],
        'logs' => ['label' => 'Logs'],
        'platform' => ['label' => 'Platform'],
        'notifications' => ['label' => 'Notifications'],
        'basic-auth' => ['label' => 'Authentication'],
        'danger' => ['label' => 'Danger zone'],
        'edge-deploys' => ['label' => 'Deploys'],
        'edge-domains' => ['label' => 'Domains'],
        'edge-build' => ['label' => 'Build'],
        'edge-deploy-triggers' => ['label' => 'Deploy triggers'],
        'edge-environment' => ['label' => 'Environment'],
        'edge-delivery' => ['label' => 'Delivery'],
        'edge-bindings' => ['label' => 'Bindings'],
        'edge-routing' => ['label' => 'Routing'],
        'edge-error-pages' => ['label' => 'Error pages'],
        'edge-crons' => ['label' => 'Crons'],
        'edge-firewall' => ['label' => 'Firewall'],
        'edge-members' => ['label' => 'Members'],
        'edge-alerts' => ['label' => 'Alerts'],
        'edge-audit' => ['label' => 'Audit log'],
        'edge-previews' => ['label' => 'Previews'],
        'edge-traffic' => ['label' => 'Traffic & analytics'],
        'edge-billing' => ['label' => 'Billing & usage'],
        'edge-logs' => ['label' => 'Build & deploy logs'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sidebar group labels
    |--------------------------------------------------------------------------
    | Display labels for the `group` keys assigned in {@see App\Support\SiteSettingsSidebar}.
    | The sidebar partial renders a heading whenever the group changes; only groups
    | that have at least one visible item produce a heading.
    */
    'nav_groups' => [
        'general' => 'General',
        'networking' => 'Networking',
        'deploy' => 'Deploy',
        'runtime' => 'Runtime',
        'observability' => 'Observability',
        'background' => 'Background',
        'access' => 'Access',
        'danger' => 'Danger',
    ],
];
