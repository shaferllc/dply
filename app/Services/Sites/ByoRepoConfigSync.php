<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Enums\SiteRedirectKind;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteRedirect;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Services\Edge\Config\EdgeRepoConfig;
use App\Services\SshConnection;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * Tier B: after a BYO git deploy, fetch dply.yaml from the server and
 * sync redirects, server crons, and deploy hooks declared in-repo.
 */
final class ByoRepoConfigSync
{
    public const MANAGED_REDIRECT_COMMENT = 'dply.yaml';

    public const MANAGED_CRON_PREFIX = '[dply.yaml]';

    public const MANAGED_SERVER_CRON_PREFIX = '[dply.yaml server]';

    public function __construct(
        private ByoRepoConfigLoader $loader,
    ) {}

    /**
     * @return array{
     *     applied: bool,
     *     source_path: ?string,
     *     redirects: int,
     *     crons: int,
     *     server_crons: int,
     *     deploy_hooks: int,
     *     warnings: list<string>
     * }
     */
    public function syncAfterDeploy(Site $site, SshConnection $ssh, string $remotePath): array
    {
        $empty = ['applied' => false, 'source_path' => null, 'redirects' => 0, 'crons' => 0, 'server_crons' => 0, 'deploy_hooks' => 0, 'warnings' => []];

        if (! Feature::active('global.byo_repo_config')) {
            return $empty;
        }

        if (! $site->server?->isVmHost()) {
            return $empty;
        }

        $fetched = $this->fetchRemoteConfig($ssh, $remotePath);
        if ($fetched === null) {
            $this->persistSnapshot($site, null);

            return $empty;
        }

        try {
            $parsed = $this->loader->parse($fetched['source'], $fetched['content']);
        } catch (\Throwable $e) {
            Log::warning('byo repo config parse failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return [
                'applied' => false,
                'source_path' => $fetched['source'],
                'redirects' => 0,
                'crons' => 0,
                'server_crons' => 0,
                'deploy_hooks' => 0,
                'warnings' => ['Could not parse dply config: '.$e->getMessage()],
            ];
        }

        $redirectCount = $this->syncRedirects($site, $parsed['config']);
        $cronCount = $this->syncCrons($site, $parsed['crons']);
        $serverCronCount = $this->syncServerCrons($site, $parsed['server_crons']);
        $hookCount = $this->syncDeployHooks($site, $parsed['deploy_hooks']);

        $snapshot = array_merge($parsed['config']->toArray(), [
            'byo_crons' => $parsed['crons'],
            'byo_server_crons' => $parsed['server_crons'],
            'deploy_hooks' => count($parsed['deploy_hooks']),
            'env_declarations' => $parsed['env_declarations'],
            'synced_at' => now()->toIso8601String(),
        ]);

        $this->persistSnapshot($site, [
            'source_path' => $fetched['source'],
            'snapshot' => $snapshot,
            'warnings' => $parsed['warnings'],
        ]);

        return [
            'applied' => true,
            'source_path' => $fetched['source'],
            'redirects' => $redirectCount,
            'crons' => $cronCount,
            'server_crons' => $serverCronCount,
            'deploy_hooks' => $hookCount,
            'warnings' => $parsed['warnings'],
        ];
    }

    /**
     * @return array{source: string, content: string}|null
     */
    private function fetchRemoteConfig(SshConnection $ssh, string $remotePath): ?array
    {
        $base = rtrim($remotePath, '/');
        foreach (['dply.yaml', 'dply.yml', 'dply.json'] as $candidate) {
            $content = trim($ssh->exec(
                sprintf('cat %s/%s 2>/dev/null', escapeshellarg($base), escapeshellarg($candidate)),
                30,
            ));
            if ($content !== '') {
                return ['source' => $candidate, 'content' => $content];
            }
        }

        return null;
    }

    private function syncRedirects(Site $site, EdgeRepoConfig $config): int
    {
        SiteRedirect::query()
            ->where('site_id', $site->id)
            ->where('comment', self::MANAGED_REDIRECT_COMMENT)
            ->delete();

        $order = 0;
        $count = 0;

        foreach ($config->redirects as $redirect) {
            SiteRedirect::query()->create([
                'site_id' => $site->id,
                'kind' => SiteRedirectKind::Http,
                'from_path' => (string) ($redirect['from'] ?? ''),
                'to_url' => (string) ($redirect['to'] ?? ''),
                'status_code' => (int) ($redirect['status'] ?? 301),
                'comment' => self::MANAGED_REDIRECT_COMMENT,
                'sort_order' => $order++,
            ]);
            $count++;
        }

        foreach ($config->rewrites as $rewrite) {
            SiteRedirect::query()->create([
                'site_id' => $site->id,
                'kind' => SiteRedirectKind::InternalRewrite,
                'from_path' => (string) ($rewrite['from'] ?? ''),
                'to_url' => (string) ($rewrite['to'] ?? ''),
                'status_code' => 0,
                'comment' => self::MANAGED_REDIRECT_COMMENT,
                'sort_order' => $order++,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array{schedule: string, command: string, user: ?string}>  $crons
     */
    private function syncCrons(Site $site, array $crons): int
    {
        $server = $site->server;
        if ($server === null) {
            return 0;
        }

        ServerCronJob::query()
            ->where('server_id', $server->id)
            ->where('site_id', $site->id)
            ->where('description', 'like', self::MANAGED_CRON_PREFIX.'%')
            ->delete();

        $count = 0;
        foreach ($crons as $cron) {
            ServerCronJob::query()->create([
                'server_id' => $server->id,
                'site_id' => $site->id,
                'cron_expression' => $cron['schedule'],
                'command' => $cron['command'],
                'user' => $cron['user'] ?? config('server_settings.deploy_user', 'dply'),
                'enabled' => true,
                'description' => self::MANAGED_CRON_PREFIX.' '.$cron['command'],
                'is_synced' => false,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array{schedule: string, command: string, user: ?string}>  $crons
     */
    private function syncServerCrons(Site $site, array $crons): int
    {
        $server = $site->server;
        if ($server === null) {
            return 0;
        }

        ServerCronJob::query()
            ->where('server_id', $server->id)
            ->whereNull('site_id')
            ->where('description', 'like', self::MANAGED_SERVER_CRON_PREFIX.'%')
            ->delete();

        $count = 0;
        foreach ($crons as $cron) {
            ServerCronJob::query()->create([
                'server_id' => $server->id,
                'site_id' => null,
                'cron_expression' => $cron['schedule'],
                'command' => $cron['command'],
                'user' => $cron['user'] ?? config('server_settings.deploy_user', 'dply'),
                'enabled' => true,
                'description' => self::MANAGED_SERVER_CRON_PREFIX.' '.$cron['command'],
                'is_synced' => false,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array{phase: string, script: string, timeout: int, sort_order: int}>  $hooks
     */
    private function syncDeployHooks(Site $site, array $hooks): int
    {
        SiteDeployHook::query()
            ->where('site_id', $site->id)
            ->where('script', 'like', ByoRepoConfigLoader::MANAGED_HOOK_PREFIX.'%')
            ->delete();

        $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
        $count = 0;
        foreach ($hooks as $hook) {
            $phase = $hook['phase'];
            SiteDeployHook::query()->create([
                'site_id' => $site->id,
                'pipeline_id' => $pipeline->id,
                'phase' => $phase,
                'hook_kind' => SiteDeployHook::KIND_SHELL,
                'anchor' => $phase,
                'script' => $hook['script'],
                'sort_order' => $hook['sort_order'],
                'timeout_seconds' => $hook['timeout'],
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array{source_path: string, snapshot: array<string, mixed>, warnings: list<string>}|null  $payload
     */
    private function persistSnapshot(Site $site, ?array $payload): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        if ($payload === null) {
            unset($meta['byo']['repo_config']);
        } else {
            $meta['byo']['repo_config'] = $payload;
        }
        $site->forceFill(['meta' => $meta])->save();
    }
}
