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

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'key' => env('CLOUDFLARE_API_KEY'),
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

        // Region → pre-baked snapshot-id map (JSON), e.g.
        //   {"nyc1":"171234567","sfo3":"171234568"}
        // When a new server's region has an entry, provisioning launches from
        // that snapshot (stack preinstalled) instead of stock Ubuntu and the
        // setup script skip-fasts. Bake with `php artisan dply:do:snapshot:bake`
        // per region; snapshots are region-scoped on DigitalOcean. Empty = off.
        'baked_snapshots' => env('DIGITALOCEAN_BAKED_SNAPSHOTS', ''),

        'ssh_user' => env('DIGITALOCEAN_SSH_USER', 'root'),
        /*
         * Optional personal access token for listing regions & sizes on the server create
         * wizard when no org credential is selected (read-only catalog). Provisioning still
         * uses the selected ProviderCredential.
         */
        'token' => env('DIGITALOCEAN_TOKEN'),
        'auto_testing_hostname_enabled' => true,
        /*
         * Universal testing-zone pool (DO). Legacy callers still read from
         * here — the provider-routing logic in TestingHostnameProvisioner
         * folds these into services.dply.testing_domains.digitalocean.
         */
        'testing_domains' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('DPLY_TESTING_DOMAINS', ''))
        ))),
        'testing_domain_strategy' => 'deterministic',
        /*
         * DNS target for a deployed serverless function's friendly hostname
         * ({slug}.{testing-domain}). An IP becomes an A record; a hostname
         * becomes a CNAME. When unset, the function host CNAMEs onto the
         * testing-domain apex (which must already resolve to the dply app).
         */
        'serverless_function_dns_target' => env('DPLY_SERVERLESS_FUNCTION_DNS_TARGET'),
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

        // Pre-baked snapshot for the fast path. Hetzner Cloud snapshots are
        // GLOBAL across locations, so this is a single snapshot id (not a
        // per-region map like DigitalOcean), e.g. HETZNER_BAKED_SNAPSHOT=171234567.
        // New non-managed servers then launch from it and skip-fast the setup
        // script. A JSON region→id map is also accepted if you ever want it.
        'baked_snapshots' => env('HETZNER_BAKED_SNAPSHOT', ''),

        'ssh_user' => env('HETZNER_SSH_USER', 'root'),
        // Create + attach a dply-managed Cloud Firewall at provision time so SSH
        // (and service ports) are reachable at Hetzner's edge. Disable to rely on
        // the project's own firewall posture.
        'manage_cloud_firewall' => env('HETZNER_MANAGE_CLOUD_FIREWALL', true),
    ],

    'linode' => [
        'default_image' => env('LINODE_DEFAULT_IMAGE', 'linode/ubuntu24.04'),
        'ssh_user' => env('LINODE_SSH_USER', 'root'),

        /*
         * App-level Linode (Akamai Connected Cloud) API token for "global" ops with
         * no connected customer credential — catalog (regions/types) browsing before
         * a credential is linked. Mirrors services.digitalocean.token /
         * services.vultr.token. Per-server provisioning still uses each server's
         * own ProviderCredential.
         */
        'token' => env('LINODE_TOKEN'),
    ],

    'vultr' => [
        'default_os_id' => env('VULTR_DEFAULT_OS_ID', 2152), // Ubuntu 24.04 LTS
        'ssh_user' => env('VULTR_SSH_USER', 'root'),

        /*
         * App-level Vultr API token for "global" operations that aren't tied to a
         * connected customer credential — catalog (regions/plans) browsing before a
         * credential is linked, and any control-plane reads. Mirrors
         * services.digitalocean.token. Per-server provisioning still uses the
         * server's own ProviderCredential.
         */
        'token' => env('VULTR_TOKEN'),
    ],

    'upcloud' => [
        'default_template' => env('UPCLOUD_DEFAULT_TEMPLATE', '01000000-0000-4000-8000-000030200100'), // Ubuntu 22.04
        'ssh_user' => env('UPCLOUD_SSH_USER', 'root'),
    ],

    'ovh' => [
        // OVH Public Cloud Ubuntu images default to the `ubuntu` login user.
        'ssh_user' => env('OVH_SSH_USER', 'ubuntu'),
        // Image name matched (case-insensitive substring) against the project's
        // image catalogue at provision time. See OvhService::resolveImageId().
        'default_image' => env('OVH_DEFAULT_IMAGE', 'Ubuntu 24.04'),
    ],

    'aws' => [
        'default_region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        /** When unset, resolveDefaultImageId() reads the regional Ubuntu SSM parameter. */
        'default_image' => env('AWS_EC2_DEFAULT_IMAGE'),
        'ami_ssm_parameter' => env(
            'AWS_EC2_AMI_SSM_PARAMETER',
            '/aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id'
        ),
        /** Existing security group with SSH ingress; when unset, Dply creates/finds dply-provision. */
        'security_group_id' => env('AWS_EC2_SECURITY_GROUP_ID'),
        'provision_security_group' => env('AWS_EC2_PROVISION_SECURITY_GROUP', true),
        'provision_security_group_name' => env('AWS_EC2_PROVISION_SECURITY_GROUP_NAME', 'dply-provision'),
        'ssh_user' => env('AWS_EC2_SSH_USER', 'ubuntu'),
    ],

    'azure' => [
        'ssh_user' => env('AZURE_SSH_USER', 'azureuser'),
        'default_resource_group' => env('AZURE_DEFAULT_RESOURCE_GROUP', 'dply'),
        'image_publisher' => env('AZURE_IMAGE_PUBLISHER', 'Canonical'),
        'image_offer' => env('AZURE_IMAGE_OFFER', 'ubuntu-24_04-lts'),
        'image_sku' => env('AZURE_IMAGE_SKU', 'server'),
        'image_version' => env('AZURE_IMAGE_VERSION', 'latest'),
        'os_disk_type' => env('AZURE_OS_DISK_TYPE', 'Standard_LRS'),
    ],

    'oracle' => [
        'ssh_user' => env('ORACLE_SSH_USER', 'ubuntu'),
        'default_shape' => env('ORACLE_DEFAULT_SHAPE', 'VM.Standard.E2.1.Micro'),
        'default_availability_domain' => env('ORACLE_DEFAULT_AVAILABILITY_DOMAIN', ''),
        'default_image_id' => env('ORACLE_DEFAULT_IMAGE_ID', ''),
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

    /*
    |--------------------------------------------------------------------------
    | Dply testing-hostname pools by DNS provider
    |--------------------------------------------------------------------------
    |
    | Per-provider lists of Dply-owned zones used to mint testing URLs for
    | newly provisioned sites. When an organization has a credential for one
    | of these providers connected, TestingHostnameProvisioner will use that
    | provider's pool + credential so the testing record lives where the
    | operator's existing DNS already is. Falls back to the digitalocean
    | pool (services.digitalocean.token or an org-level DO credential) when
    | no provider-specific match is available.
    |
    | Each env var is a comma-separated list of zones Dply controls on the
    | given provider, e.g. DPLY_TESTING_DOMAINS_HETZNER="dply.forum".
    |
    */
    'dply' => [
        'testing_domains' => [
            'digitalocean' => array_values(array_unique(array_filter(array_merge(
                array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', (string) env('DPLY_TESTING_DOMAINS', ''))),
                array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', (string) env('DPLY_TESTING_DOMAINS_DIGITALOCEAN', ''))),
            )))),
            'hetzner' => array_values(array_filter(array_map(
                static fn (string $v): string => strtolower(trim($v)),
                explode(',', (string) env('DPLY_TESTING_DOMAINS_HETZNER', ''))
            ))),
            'cloudflare' => array_values(array_filter(array_map(
                static fn (string $v): string => strtolower(trim($v)),
                explode(',', (string) env('DPLY_TESTING_DOMAINS_CLOUDFLARE', ''))
            ))),
        ],
    ],

];
