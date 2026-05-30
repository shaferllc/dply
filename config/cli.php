<?php

/**
 * dply CLI defaults — device-flow scopes and token naming.
 */
return [
    'token_name' => 'dply CLI',

    /*
    |--------------------------------------------------------------------------
    | Default scopes offered during `dply login` device approval
    |--------------------------------------------------------------------------
    */
    'device_flow_abilities' => [
        'edge.read',
        'edge.deploy',
        'edge.write',
        'servers.read',
        'sites.read',
        'sites.deploy',
        'system_users.read',
        'system_users.write',
        'system_users.delete',
    ],

    /*
    |--------------------------------------------------------------------------
    | Role caps — intersected with device_flow_abilities at approval time
    |--------------------------------------------------------------------------
    */
    'device_flow_role_caps' => [
        'admin' => [
            'edge.read',
            'edge.deploy',
            'edge.write',
            'servers.read',
            'sites.read',
            'sites.deploy',
            'system_users.read',
            'system_users.write',
            'system_users.delete',
        ],
        'deployer' => [
            'edge.read',
            'edge.deploy',
            'servers.read',
            'sites.read',
            'sites.deploy',
            'system_users.read',
            'system_users.write',
        ],
        'member' => [
            'edge.read',
            'servers.read',
            'sites.read',
            'system_users.read',
        ],
    ],

    'device_flow_scope_labels' => [
        'edge.read' => 'Read Edge sites, deployments, and logs',
        'edge.deploy' => 'Deploy, roll back, and promote Edge previews',
        'edge.write' => 'Manage Edge custom domains and cache',
        'servers.read' => 'List servers and read server metadata',
        'sites.read' => 'List BYO sites and read deployment history',
        'sites.deploy' => 'Trigger BYO site deploys',
        'system_users.read' => 'List Linux system users on servers',
        'system_users.write' => 'Create and update system users',
        'system_users.delete' => 'Remove system users from servers',
    ],
];
