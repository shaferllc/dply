<?php

/**
 * dply CLI defaults — device-flow scopes and token naming.
 */
return [
    'token_name' => 'dply CLI',

    /*
    |--------------------------------------------------------------------------
    | CLI distribution (hosted from this app until @dply/cli is on npm)
    |--------------------------------------------------------------------------
    |
    | install.sh and dply-cli.tgz are served at /cli/install.sh and
    | /cli/dply-cli.tgz. Default method is tarball — npm is opt-in only when
    | DPLY_CLI_NPM_PUBLISHED=true after you publish @dply/cli.
    |
    */
    'install_method' => env('DPLY_CLI_INSTALL_METHOD', 'tarball'),
    'npm_published' => filter_var(env('DPLY_CLI_NPM_PUBLISHED', false), FILTER_VALIDATE_BOOLEAN),
    'npm_package' => env('DPLY_CLI_NPM_PACKAGE', '@dply/cli'),

    /*
    |--------------------------------------------------------------------------
    | Default API origin baked into hosted CLI tarballs (and install.sh)
    |--------------------------------------------------------------------------
    |
    | Falls back to APP_URL so local Valet/Herd installs default to dplyi.test
    | when that is your APP_URL. Override with DPLY_CLI_DEFAULT_BASE_URL.
    |
    */
    'default_base_url' => rtrim((string) env('DPLY_CLI_DEFAULT_BASE_URL', env('APP_URL', 'https://dply.dev')), '/'),

    /*
    |--------------------------------------------------------------------------
    | Default scopes offered during `dply login` device approval
    |--------------------------------------------------------------------------
    */
    'device_flow_abilities' => [
        'account.read',
        'account.write',
        'billing.read',
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
            'account.read',
            'account.write',
            'billing.read',
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
            'account.read',
            'account.write',
            'edge.read',
            'edge.deploy',
            'servers.read',
            'sites.read',
            'sites.deploy',
            'system_users.read',
            'system_users.write',
        ],
        'member' => [
            'account.read',
            'account.write',
            'edge.read',
            'servers.read',
            'sites.read',
            'system_users.read',
        ],
    ],

    'device_flow_scope_labels' => [
        'account.read' => 'Read your profile, organizations, and CLI sessions',
        'account.write' => 'Revoke CLI sessions (this machine or others)',
        'billing.read' => 'View plan estimates, breakdown, and invoices',
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
