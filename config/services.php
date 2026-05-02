<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'digitalocean' => [
        'default_image' => env('DIGITALOCEAN_DEFAULT_IMAGE', 'ubuntu-24-04-x64'),
        'ssh_user' => env('DIGITALOCEAN_SSH_USER', 'root'),
        /*
         * Optional personal access token for listing regions & sizes on the server create
         * wizard when no org credential is selected (read-only catalog). Provisioning still
         * uses the selected ProviderCredential.
         */
        'token' => env('DIGITALOCEAN_TOKEN'),
        'auto_testing_hostname_enabled' => (bool) env('DPLY_AUTO_TESTING_HOSTNAME_ENABLED', false),
        'testing_domains' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('DPLY_TESTING_DOMAINS', ''))
        ))),
        'testing_domain_strategy' => env('DPLY_TESTING_DOMAIN_STRATEGY', 'deterministic'),
    ],

    /*
    | Optional OAuth app for Server providers → DigitalOcean (separate from Git OAuth).
    | Register at https://cloud.digitalocean.com/account/api/applications
    | Redirect URI must match DIGITALOCEAN_OAUTH_REDIRECT_URI, or if unset,
    | route('credentials.oauth.digitalocean.callback') using APP_URL (use your Expose URL locally).
    */
    'digitalocean_oauth' => [
        'client_id' => env('DIGITALOCEAN_OAUTH_CLIENT_ID'),
        'client_secret' => env('DIGITALOCEAN_OAUTH_CLIENT_SECRET'),
        'redirect' => env('DIGITALOCEAN_OAUTH_REDIRECT_URI'),
    ],

    'zerossl' => [
        'access_key' => env('ZEROSSL_ACCESS_KEY'),
        'poll_attempts' => (int) env('ZEROSSL_POLL_ATTEMPTS', 10),
        'poll_sleep_ms' => (int) env('ZEROSSL_POLL_SLEEP_MS', 2000),
    ],

    'hetzner' => [
        'default_image' => env('HETZNER_DEFAULT_IMAGE', 'ubuntu-24.04'),
        'ssh_user' => env('HETZNER_SSH_USER', 'root'),
    ],

    'linode' => [
        'default_image' => env('LINODE_DEFAULT_IMAGE', 'linode/ubuntu24.04'),
        'ssh_user' => env('LINODE_SSH_USER', 'root'),
    ],

    'vultr' => [
        'default_os_id' => env('VULTR_DEFAULT_OS_ID', 2152), // Ubuntu 24.04 LTS
        'ssh_user' => env('VULTR_SSH_USER', 'root'),
    ],

    'upcloud' => [
        'default_template' => env('UPCLOUD_DEFAULT_TEMPLATE', '01000000-0000-4000-8000-000030200100'), // Ubuntu 22.04
        'ssh_user' => env('UPCLOUD_SSH_USER', 'root'),
    ],

    'scaleway' => [
        'default_image' => env('SCALEWAY_DEFAULT_IMAGE', 'ubuntu_jammy'),
        'ssh_user' => env('SCALEWAY_SSH_USER', 'root'),
    ],

    'equinix_metal' => [
        'default_os' => env('EQUINIX_METAL_DEFAULT_OS', 'ubuntu_22_04'),
        'ssh_user' => env('EQUINIX_METAL_SSH_USER', 'root'),
    ],

    'ovh' => [
        'ssh_user' => env('OVH_SSH_USER', 'root'),
    ],

    'rackspace' => [
        'ssh_user' => env('RACKSPACE_SSH_USER', 'root'),
    ],

    'fly_io' => [
        'api_host' => env('FLY_API_HOSTNAME', 'https://api.machines.dev'),
        'default_image' => env('FLY_DEFAULT_IMAGE', 'registry-1.docker.io/library/ubuntu:22.04'),
        'default_vm_size' => env('FLY_DEFAULT_VM_SIZE', 'shared-cpu-1x'),
        'ssh_user' => env('FLY_SSH_USER', 'root'),
    ],

    'render' => [
        'ssh_user' => env('RENDER_SSH_USER', 'root'),
    ],

    'railway' => [
        'ssh_user' => env('RAILWAY_SSH_USER', 'root'),
    ],

    'coolify' => [
        'ssh_user' => env('COOLIFY_SSH_USER', 'root'),
    ],

    'cap_rover' => [
        'ssh_user' => env('CAP_ROVER_SSH_USER', 'root'),
    ],

    'aws' => [
        'default_region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'default_image' => env('AWS_EC2_DEFAULT_IMAGE', 'ami-0c55b159cbfafe1f0'),
        'ssh_user' => env('AWS_EC2_SSH_USER', 'ubuntu'),
    ],

    'gcp' => [
        'default_zone' => env('GCP_DEFAULT_ZONE', 'us-central1-a'),
        'ssh_user' => env('GCP_SSH_USER', 'ubuntu'),
    ],

    'azure' => [
        'ssh_user' => env('AZURE_SSH_USER', 'azureuser'),
    ],

    'oracle' => [
        'ssh_user' => env('ORACLE_SSH_USER', 'ubuntu'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI', env('APP_URL').'/auth/github/callback'),
        /** Used for Quick deploy (repo webhooks). Re-link accounts after changing scopes. */
        'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('GITHUB_SCOPES', 'read:user,repo,admin:repo_hook'))))),
    ],

    'bitbucket' => [
        'client_id' => env('BITBUCKET_CLIENT_ID'),
        'client_secret' => env('BITBUCKET_CLIENT_SECRET'),
        'redirect' => env('BITBUCKET_REDIRECT_URI', env('APP_URL').'/auth/bitbucket/callback'),
        'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('BITBUCKET_SCOPES', 'account,repository:write,webhook'))))),
    ],

    'gitlab' => [
        'client_id' => env('GITLAB_CLIENT_ID'),
        'client_secret' => env('GITLAB_CLIENT_SECRET'),
        'redirect' => env('GITLAB_REDIRECT_URI', env('APP_URL').'/auth/gitlab/callback'),
        'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('GITLAB_SCOPES', 'read_user,api'))))),
    ],

];
