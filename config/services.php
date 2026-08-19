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
        // Accept either name: the mail transport key was historically provisioned
        // as CLOUDFLARE_KEY in env, while this config read only CLOUDFLARE_API_KEY —
        // the mismatch left services.cloudflare.key null and crashed CloudflareTransport.
        'key' => env('CLOUDFLARE_API_KEY', env('CLOUDFLARE_KEY')),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     | Telegram bot behind the one-click "Connect Telegram" notification channel.
     | Create with @BotFather (/newbot) to get TELEGRAM_BOT_TOKEN.
     | TELEGRAM_WEBHOOK_SECRET is invented by you (any random string) — Telegram
     | echoes it on every delivery and it is the only thing authenticating the
     | public /hooks/telegram endpoint, so treat it like a password.
     | Register the webhook with: php artisan telegram:set-webhook
     | Telegram requires a public HTTPS URL, so use your Expose URL locally.
     | Optional: without it the button hides and operators paste a bot token +
     | chat ID by hand instead.
     */
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
    ],

    /*
     | Discord application backing the one-click "Add to Discord" notification
     | channel. Create at https://discord.com/developers/applications:
     |   OAuth2 → Redirects: add DISCORD_REDIRECT_URI (Discord rejects `.test`
     |   hosts — use your Expose URL locally); unset falls back to APP_URL +
     |   route('notifications.oauth.discord.callback').
     |   Bot → Reset Token gives DISCORD_BOT_TOKEN.
     | All three are required: unlike Slack, the bot token is application-wide
     | and does NOT come out of the OAuth exchange, so without it the flow would
     | connect a server that can never actually receive a message.
     | Optional overall: without it the button hides and operators paste a
     | webhook URL by hand instead.
     */
    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect' => env('DISCORD_REDIRECT_URI'),
        'bot_token' => env('DISCORD_BOT_TOKEN'),
    ],

    /*
     | Slack app backing the one-click "Add to Slack" notification channel.
     | Register at https://api.slack.com/apps, add the bot scopes listed in
     | SlackOAuthController::SCOPES, and set the redirect URL to
     | SLACK_REDIRECT_URI — or, if unset, route('notifications.oauth.slack.callback')
     | using APP_URL (use your Expose URL locally; Slack rejects `.test` hosts).
     | Turn on "Manage Distribution" so workspaces other than your own can install.
     | Optional: without it the button hides and operators paste an incoming
     | webhook URL by hand instead. Platform admin can also save these under
     | /admin/connections (DB overlay; does not write this file).
     */
    'slack' => [
        'client_id' => env('SLACK_CLIENT_ID'),
        'client_secret' => env('SLACK_CLIENT_SECRET'),
        'redirect' => env('SLACK_REDIRECT_URI'),

        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     | Intercom, behind the "Intercom" notification channel and Laravel's
     | `intercom` notification driver (App\Modules\Notifications\Channels\Intercom).
     | Get the token at https://app.intercom.com/a/apps/_/developer-hub → your app
     | (create one if needed; it never has to be published) → Configure →
     | Authentication. Enable the "Write conversations" permission there BEFORE
     | copying the token — changing permissions does not update an already-issued
     | token. The token is scoped to ONE workspace and ONE region.
     |
     | Optional, and normally left unset: operators paste their own token on each
     | notification channel so every org reaches its own Intercom workspace. This
     | app-level token is only the fallback for `$user->notify()` on a notifiable
     | with no Intercom channel of its own — the setup that
     | laravel-notification-channels/intercom documents. INTERCOM_ADMIN_ID is the
     | teammate messages are sent as (Intercom → Settings → Teammates, the numeric
     | ID in the teammate's URL, on the same workspace as the token); Intercom
     | rejects a message with no `from`, so without it the fallback cannot send.
     |
     | INTERCOM_REGION is us (default), eu, or au. A token issued for one region
     | 401s against the others, which reads exactly like a bad token.
     | See docs/NOTIFICATIONS_INTERCOM.md.
     */
    'intercom' => [
        'token' => env('INTERCOM_API_KEY'),
        'region' => env('INTERCOM_REGION', 'us'),
        'admin_id' => env('INTERCOM_ADMIN_ID'),
    ],

    /*
     | PagerDuty, behind the "PagerDuty" notification channel and Laravel's
     | `PagerDuty` notification driver (App\Modules\Notifications\Channels\PagerDuty).
     |
     | The credential is an Events API v2 *integration key*, taken from a single
     | PagerDuty service: PagerDuty → Services → your service → Integrations →
     | Add an integration → Events API v2. Note "v2" — the older Events API v1
     | key has a different payload shape and is not supported.
     |
     | Optional, and normally left unset: operators paste their own key on each
     | notification channel, which is how they choose WHICH service (and so which
     | escalation policy) an alert pages. This app-level key is only the fallback
     | for $user->notify() on a notifiable with no PagerDuty channel of its own.
     |
     | PAGERDUTY_REGION is us (default) or eu. A key from one region is rejected
     | by the other with a 400 that names the routing key — so it reads like a
     | bad key rather than a wrong region.
     | See docs/NOTIFICATIONS_PAGERDUTY.md.
     */
    'pagerduty' => [
        'routing_key' => env('PAGERDUTY_ROUTING_KEY'),
        'region' => env('PAGERDUTY_REGION', 'us'),
    ],

    /*
     | Microsoft Teams, behind the "Microsoft Teams" notification channel and the
     | `microsoftTeams` notification driver.
     |
     | There is nothing to set here. Each channel stores its own Power Automate
     | Workflows URL, which already encodes the target team and channel — there is
     | no app-level credential to share.
     |
     | Worth recording why the payload looks the way it does: dply posts an
     | Adaptive Card to a Workflows webhook, NOT a MessageCard to an Office 365
     | connector. Microsoft retired connectors between 18 and 22 May 2026, so the
     | "Incoming Webhook" that most Teams guides still describe no longer
     | delivers. MicrosoftTeamsClient refuses a *.webhook.office.com URL outright
     | rather than posting into the void.
     | See docs/NOTIFICATIONS_MICROSOFT_TEAMS.md.
     */

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
         * Legacy alias of the VM testing-zone pool. The list lives in
         * config/product/testing_domains.php — not DPLY_TESTING_DOMAINS.
         */
        'testing_domains' => array_values((array) ((require __DIR__.'/product/testing_domains.php')['vm'] ?? [])),
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
    /*
     | Dropbox app used for the one-click "Connect Dropbox" backup destination.
     | Optional: without it the button hides and operators add an app key +
     | refresh token by hand instead.
     */
    /*
     | Google Cloud OAuth client used for the one-click "Connect Google Drive"
     | backup destination. Optional, same as Dropbox.
     */
    'google_drive' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_DRIVE_REDIRECT_URI'),
    ],

    'dropbox' => [
        'client_id' => env('DROPBOX_APP_KEY'),
        'client_secret' => env('DROPBOX_APP_SECRET'),
        'redirect' => env('DROPBOX_REDIRECT_URI'),
    ],

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
        'default_os_id' => env('VULTR_DEFAULT_OS_ID', 2284), // Ubuntu 24.04 LTS x64
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

    'namecheap' => [
        'api_user' => env('NAMECHEAP_API_USER'),
        'api_key' => env('NAMECHEAP_API_KEY'),
        'api_username' => env('NAMECHEAP_API_USERNAME', env('NAMECHEAP_API_USER')),
        'client_ip' => env('NAMECHEAP_CLIENT_IP'),
        'sandbox' => filter_var(env('NAMECHEAP_SANDBOX', false), FILTER_VALIDATE_BOOLEAN),
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
    | Dply-owned testing zones live in config/product/testing_domains.php
    | and are written through Namecheap (services.namecheap). Legacy
    | per-provider env lists (DPLY_TESTING_DOMAINS_*) are no longer read.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Lookout (uselookout.app) error-tracking resource
    |--------------------------------------------------------------------------
    |
    | The one-click Lookout error-tracking binding. `url` is the Lookout
    | instance dply provisions projects against (the hosted SaaS by default).
    | `provision_token` is reserved for the future dply-managed account model
    | (a service token against POST /api/provision); the shipped per-customer
    | model uses the customer's own Lookout API token and needs no value here.
    | See docs/LOOKOUT_RESOURCE.md.
    |
    */
    'lookout' => [
        'url' => env('LOOKOUT_URL', 'https://uselookout.app'),
        // 'byo' (default): each customer pastes their own Lookout API token and
        // the project lands in their account. 'managed': dply mints projects
        // under its own org via the service token + POST /api/provision.
        'account_model' => env('LOOKOUT_ACCOUNT_MODEL', 'byo'),
        // Service token + default org for the 'managed' model only.
        'provision_token' => env('LOOKOUT_PROVISION_TOKEN'),
        'managed_organization_id' => env('LOOKOUT_MANAGED_ORG_ID'),
    ],

    'dply' => [
        'testing_domains' => [
            'cloudflare' => array_values((array) ((require __DIR__.'/product/testing_domains.php')['vm'] ?? [])),
            'namecheap' => array_values((array) ((require __DIR__.'/product/testing_domains.php')['vm'] ?? [])),
        ],
    ],

];
