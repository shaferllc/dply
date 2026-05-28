<?php

/*
|--------------------------------------------------------------------------
| Platform admin — curated Pennant flags
|--------------------------------------------------------------------------
|
| Subset of config/features.php exposed on /admin for per-org overrides
| (org_groups) and app-wide toggles (global_groups). Not every flag belongs
| here — provider rollouts stay env-driven unless we add them later.
|
*/

return [

    'org_groups' => [
        'Surfaces' => [
            'surface.cloud' => 'Cloud apps',
            'surface.edge' => 'Edge',
            'surface.serverless' => 'Serverless',
            'surface.marketplace' => 'Marketplace',
            'surface.fleet' => 'Fleet ops views',
            'surface.projects' => 'Projects',
            'surface.scripts' => 'Scripts',
            'surface.status_pages' => 'Status pages',
        ],
        'Workspace' => [
            'workspace.ephemeral_credentials' => 'Ephemeral deploy credentials',
            'workspace.caches' => 'Server caches',
            'workspace.schedule' => 'Schedule workspace',
            'workspace.activity' => 'Activity log tab',
            'workspace.run' => 'Run / saved commands',
            'workspace.console' => 'Browser console',
            'workspace.files' => 'Remote files',
            'workspace.services' => 'System services',
            'workspace.system_users' => 'System users',
            'workspace.insights' => 'Insights',
            'workspace.cluster' => 'Cluster',
        ],
        'Launch' => [
            'launch.full_stack_wizard' => 'Full-stack launch wizard',
            'launch.standby_blueprint' => 'Standby failover blueprints',
        ],
    ],

    'global_groups' => [
        'Billing & access' => [
            'global.billing_enabled' => 'Billing UI + cost observatory',
            'global.signups_open' => 'Public signups',
        ],
        'Product' => [
            'global.byo_repo_config' => 'dply.yaml BYO sync',
            'global.edge_deploy_replay' => 'Edge shadow replay',
            'global.ops_copilot' => 'Ops Copilot (deploy triage)',
        ],
        'Operations' => [
            'global.maintenance_mode' => 'Maintenance mode',
        ],
    ],

];
