<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnectionFactory;

/**
 * After a site's .env is (re)written on the server, make the change actually
 * take effect on the RUNNING app — and only when it's safe to.
 *
 * The trap this closes: when a Laravel app caches its config
 * (bootstrap/cache/config.php), it reads the CACHED config at runtime and
 * ignores .env entirely. So a bare .env write is inert until the cache is
 * rebuilt — and a later, unrelated `config:cache` is what silently bakes
 * whatever happens to be on disk (the prod-reverb incident). This rebuilds the
 * cache from the freshly written .env and reloads the runtime in one step.
 *
 * Guarded: it refuses to apply an env that is missing required keys or has an
 * empty APP_KEY (which would 500 the app or break decryption of every stored
 * secret). On refusal it throws WITHOUT rebuilding — so the last-good cached
 * config keeps serving and a fat-fingered edit can't take the site down.
 *
 * A no-op for sites that don't cache config (they read .env live already) and
 * for servers without env-push support.
 */
final class SiteEnvRuntimeApplier
{
    public function __construct(
        private SshConnectionFactory $sshFactory,
        private RequiredEnvEvaluator $requiredEnv,
        private SiteDeployPipelineRunner $pipelineRunner,
    ) {}

    public function apply(Site $site): string
    {
        $server = $site->server;
        if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
            return '';
        }
        if (! $server->hostCapabilities()->supportsEnvPushToHost()) {
            return '';
        }

        $base = rtrim($site->effectiveRepositoryPath(), '/');
        $active = $site->isAtomicDeploys() ? $base.'/current' : $base;
        $activeEsc = escapeshellarg($active);
        $ssh = $this->sshFactory->forServer($server);

        // Only sites that CACHE config need a rebuild; otherwise .env is read
        // live and the write already took effect.
        $cached = trim($ssh->exec(
            sprintf('[ -f %s/bootstrap/cache/config.php ] && echo CACHED || echo NONE', $activeEsc),
            30
        ));
        if (! str_contains($cached, 'CACHED')) {
            return "[dply] env apply: config not cached — .env is read live, nothing to rebuild\n";
        }

        // Guard 1: APP_KEY must be present. An empty key nulls APP_KEY at runtime
        // and breaks decryption of every stored secret — never apply that.
        $appKey = trim($ssh->exec(
            sprintf('grep -E "^APP_KEY=" %s/.env 2>/dev/null | head -n1 | cut -d= -f2-', $activeEsc),
            30
        ));
        $appKey = trim($appKey, "\"' ");
        if ($appKey === '' || $appKey === 'base64:') {
            throw new \RuntimeException('Refusing to apply env: APP_KEY is empty — leaving the last-good cached config running.');
        }

        // Guard 2: no required (no-default) keys missing — same gate the deploy
        // pipeline uses. evaluateAndRecord() returns the still-missing keys, or
        // null/[] when the gate passes.
        $missing = $this->requiredEnv->evaluateAndRecord($site);
        if (is_array($missing) && $missing !== []) {
            $keys = array_map(
                static fn ($entry): string => is_array($entry) ? (string) ($entry['key'] ?? '?') : (string) $entry,
                $missing,
            );
            throw new \RuntimeException(
                'Refusing to apply env: required keys still missing ('.implode(', ', $keys).') — last-good cached config left running.'
            );
        }

        $log = "[dply] env apply: rebuilding cached config + reloading runtime\n";
        $log .= $ssh->exec(sprintf(
            'cd %s && php artisan config:clear 2>&1; php artisan config:cache 2>&1',
            $activeEsc
        ), 120);

        $restart = $this->pipelineRunner->runManagedRestart($ssh, $site, $active);
        $log .= (string) ($restart['log'] ?? '');

        return $log;
    }
}
