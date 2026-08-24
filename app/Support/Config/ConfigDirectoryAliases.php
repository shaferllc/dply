<?php

declare(strict_types=1);

namespace App\Support\Config;

/**
 * Maps legacy top-level config keys onto the nested trees loaded from
 * config/product/*, config/servers/*, and the other domain folders.
 *
 * Laravel turns config/servers/logs.php into config('servers.logs'). Callers
 * still use config('server_logs') — this copies each nested tree back onto
 * the old key after the file loader runs. Files are required (not read from
 * the already-aliased repository) so parent keys like insights → insights.core
 * stay idempotent. config:cache already contains both keys, so apply() is a
 * no-op when the configuration is cached.
 */
final class ConfigDirectoryAliases
{
    /**
     * Legacy key => nested key after the folder move.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'dply_ai' => 'product.ai',
        'dply_runtime' => 'product.runtime',
        'feedback' => 'product.feedback',
        'admin' => 'product.admin',
        'api_token_permissions' => 'product.api_token_permissions',
        'audit' => 'product.audit',
        'backup_staging' => 'product.backup_staging',
        'bundle' => 'product.bundle',
        'cache_service' => 'product.cache_service',
        'caddy_modules' => 'product.caddy_modules',
        'cli' => 'product.cli',
        'console_actions' => 'product.console_actions',
        'contextual-docs' => 'product.contextual-docs',
        'contextual-docs-maps' => 'product.contextual-docs-maps',
        'cron_workspace' => 'product.cron_workspace',
        'docs' => 'product.docs',
        'dply' => 'product.dply',
        'kubernetes' => 'product.kubernetes',
        'laravel_site_console' => 'product.laravel_site_console',
        'log_drains' => 'product.log_drains',
        'lookout' => 'product.lookout',
        'managed_servers' => 'product.managed_servers',
        'migration_sources' => 'product.migration_sources',
        'object_storage' => 'product.object_storage',
        'passkeys' => 'product.passkeys',
        'preview' => 'product.preview',
        'profile_options' => 'product.profile_options',
        'queue_service' => 'product.queue_service',
        'quick_download' => 'product.quick_download',
        'realtime' => 'product.realtime',
        'referral' => 'product.referral',
        'remediations' => 'product.remediations',
        'scaffold_placeholder' => 'product.scaffold_placeholder',
        'secret_vault' => 'product.secret_vault',
        'self_manage' => 'product.self_manage',
        'setup_scripts' => 'product.setup_scripts',
        'snapshot_s3' => 'product.snapshot_s3',
        'solo' => 'product.solo',
        'standby_blueprints' => 'product.standby_blueprints',
        'subscription' => 'product.subscription',
        'testing_domains' => 'product.testing_domains',
        'user_preferences' => 'product.user_preferences',
        'vat' => 'product.vat',
        'warm_pool' => 'product.warm_pool',
        'webserver_snippets' => 'product.webserver_snippets',
        'webserver_templates' => 'product.webserver_templates',
        'server_blueprint' => 'servers.blueprint',
        'server_cache' => 'servers.cache',
        'server_cert_inventory' => 'servers.cert_inventory',
        'server_cost_card' => 'servers.cost_card',
        'server_create' => 'servers.create',
        'server_cron' => 'servers.cron',
        'server_daemon_slo' => 'servers.daemon_slo',
        'server_database' => 'servers.database',
        'server_deploy_policy' => 'servers.deploy_policy',
        'server_error_codes' => 'servers.error_codes',
        'server_file_browser' => 'servers.file_browser',
        'server_firewall' => 'servers.firewall',
        'server_health' => 'servers.health',
        'server_images' => 'servers.images',
        'server_logs' => 'servers.logs',
        'server_maintenance' => 'servers.maintenance',
        'server_manage' => 'servers.manage',
        'server_metrics' => 'servers.metrics',
        'server_patch_advisor' => 'servers.patch_advisor',
        'server_php_extensions' => 'servers.php_extensions',
        'server_providers' => 'servers.providers',
        'server_provision' => 'servers.provision',
        'server_provision_fake' => 'servers.provision_fake',
        'server_provision_options' => 'servers.provision_options',
        'server_release_hygiene' => 'servers.release_hygiene',
        'server_resources' => 'servers.resources',
        'server_security_digest' => 'servers.security_digest',
        'server_settings' => 'servers.settings',
        'server_shared_host' => 'servers.shared_host',
        'server_ssh_access' => 'servers.ssh_access',
        'server_ssh_keys' => 'servers.ssh_keys',
        'server_ssh_sessions' => 'servers.ssh_sessions',
        'server_system_logs' => 'servers.system_logs',
        'server_workspace' => 'servers.workspace',
        'server_services' => 'servers.services',
        'insights' => 'insights.core',
        'insights_eol' => 'insights.eol',
        'insights_nodejs_eol' => 'insights.nodejs_eol',
        'insights_playbooks' => 'insights.playbooks',
        'insights_workspace' => 'insights.workspace',
        'sites' => 'sites.core',
        'site_deploy_pipeline' => 'sites.deploy_pipeline',
        'site_deploy_pipeline_starters' => 'sites.deploy_pipeline_starters',
        'site_deploy_pipeline_templates' => 'sites.deploy_pipeline_templates',
        'site_file_backup' => 'sites.file_backup',
        'site_settings' => 'sites.settings',
        'site_systemd_presets' => 'sites.systemd_presets',
        'site_uptime' => 'sites.uptime',
        'notifications' => 'notifications.core',
        'notification_channels' => 'notifications.channels',
        'notification_events' => 'notifications.events',
        'deploy' => 'deploy.core',
        'deploy_templates' => 'deploy.templates',
        'script_marketplace' => 'marketplace.scripts',
        'script_marketplace_tags' => 'marketplace.tags',
    ];

    /**
     * Root config/*.php files that may remain (Laravel, packages, Pennant landmines).
     *
     * @var list<string>
     */
    public const ROOT_ALLOW_LIST = [
        'admin_feature_flags.php',
        'app.php',
        'auth.php',
        'blade-icons.php',
        'broadcasting.php',
        'cache.php',
        'database.php',
        'debugbar.php',
        'features.php',
        'filesystems.php',
        'horizon.php',
        'logging.php',
        'mail.php',
        'octane.php',
        'pennant.php',
        'pulse.php',
        'queue.php',
        'reverb.php',
        'services.php',
        'session.php',
    ];

    public static function pathForNestedKey(string $nested): string
    {
        $parts = explode('.', $nested);
        $file = array_pop($parts);

        return config_path(implode(DIRECTORY_SEPARATOR, [...$parts, $file]).'.php');
    }

    public static function apply(): void
    {
        if (app()->configurationIsCached()) {
            return;
        }

        foreach (self::MAP as $legacy => $nested) {
            config()->set($legacy, require self::pathForNestedKey($nested));
        }
    }
}
