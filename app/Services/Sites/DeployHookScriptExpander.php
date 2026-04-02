<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;

/**
 * Replaces documented placeholders in deploy hook shell scripts before execution.
 *
 * Supported tokens (see site settings Deploy script variables):
 * {SITE_NAME}, {SITE_DOMAIN}, {SITE_PATH}, {BRANCH}, {DEPLOY_ENV}, {PHP_VERSION}, {RAILS_ENV}
 */
final class DeployHookScriptExpander
{
    /**
     * @return array<string, string>
     */
    public function tokenMap(Site $site): array
    {
        $site->loadMissing('domains');
        $primary = $site->primaryDomain();
        $domain = $primary !== null && trim((string) $primary->hostname) !== ''
            ? strtolower(trim($primary->hostname))
            : (string) ($site->testingHostname() ?: '');

        $railsRuntime = is_array($site->meta['rails_runtime'] ?? null) ? $site->meta['rails_runtime'] : [];
        $railsEnv = trim((string) ($railsRuntime['env'] ?? ''));
        if ($railsEnv === '') {
            $railsEnv = 'production';
        }

        return [
            '{SITE_NAME}' => (string) $site->name,
            '{SITE_DOMAIN}' => $domain,
            '{SITE_PATH}' => $site->effectiveRepositoryPath(),
            '{BRANCH}' => (string) ($site->git_branch ?: 'main'),
            '{DEPLOY_ENV}' => (string) ($site->deployment_environment ?? 'production'),
            '{PHP_VERSION}' => (string) ($site->php_version ?? ''),
            '{RAILS_ENV}' => $railsEnv,
        ];
    }

    public function expand(string $script, Site $site): string
    {
        $map = $this->tokenMap($site);
        $out = $script;
        foreach ($map as $token => $value) {
            $out = str_replace($token, $value, $out);
        }

        return $out;
    }
}
