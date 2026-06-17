<?php

namespace App\Services\Insights\FixActions;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightFixActionInterface;
use App\Services\Insights\Contracts\RevertableInsightFixActionInterface;
use App\Services\Insights\FixResult;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerPhpConfigEditor;
use App\Support\Servers\ServerInstalledServices;

/**
 * Bump PHP-FPM `pm.max_children` in the default www pool when a runner has detected
 * saturation. Backs the file up first (timestamped copy on disk; path stored in
 * meta.backup_path), then writes via {@see ServerPhpConfigEditor::saveTarget()} which
 * runs `php-fpm -tt` validation before the live file is replaced and reloads FPM
 * on success.
 *
 * Sizing: target ~60% of total RAM for FPM, divided by ~30MB/worker estimate.
 * Configurable via fix params: ram_share_pct (default 60), per_worker_mb (default 30),
 * max_children_cap (default 256).
 */
class BumpFpmWorkersFixAction implements InsightFixActionInterface, RevertableInsightFixActionInterface
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
        protected ServerPhpConfigEditor $editor,
    ) {}

    /**
     * @param  array<string, mixed> $params
     */
    public function preflight(Server $server, ?Site $site, InsightFinding $finding, array $params): ?string
    {
        if (! $server->isReady()) {
            return __('Server is not ready.');
        }
        if (blank($server->ip_address) || blank($server->ssh_private_key)) {
            return __('SSH access is not configured for this server.');
        }

        $phpVersion = $finding->meta['signal']['php_version'] ?? null;
        if (! is_string($phpVersion) || ! preg_match('/^\d+\.\d+$/', $phpVersion)) {
            return __('Could not determine the PHP version for this finding.');
        }

        $current = (int) ($finding->meta['signal']['max_children'] ?? 0);
        if ($current <= 0) {
            return __('Current pm.max_children value is missing from the finding signal.');
        }

        $totalRamMb = $this->resolveTotalRamMb($server);
        if ($totalRamMb === null) {
            return __('Server total RAM is unknown — cannot size new pm.max_children safely.');
        }

        $proposed = $this->computeProposedMaxChildren($current, $totalRamMb, $params);
        if ($proposed <= $current) {
            return __('Computed pm.max_children (:n) is not higher than current (:c). Adjust thresholds or check server RAM.', [
                'n' => $proposed,
                'c' => $current,
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $params
     */
    public function apply(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult
    {
        $phpVersion = (string) $finding->meta['signal']['php_version'];
        $current = (int) $finding->meta['signal']['max_children'];
        $totalRamMb = (int) $this->resolveTotalRamMb($server);
        $proposed = $this->computeProposedMaxChildren($current, $totalRamMb, $params);

        $poolPath = "/etc/php/{$phpVersion}/fpm/pool.d/www.conf";
        $backupPath = $poolPath.'.dply-backup-'.now()->format('YmdHis');

        // 1. Backup live file in-place.
        $backupScript = sprintf(
            'set -eu; if [ ! -f %1$s ]; then echo "missing"; exit 1; fi; cp -p %1$s %2$s; echo "backed-up"',
            escapeshellarg($poolPath),
            escapeshellarg($backupPath),
        );
        try {
            $this->remote->runInlineBash($server, 'insight-fix-fpm-backup', $backupScript, 30, true);
        } catch (\Throwable $e) {
            return FixResult::failure(__('Failed to back up :path: :err', ['path' => $poolPath, 'err' => $e->getMessage()]));
        }

        // 2. Read current content via the editor, substitute pm.max_children, save.
        try {
            $opened = $this->editor->openTarget($server, $phpVersion, 'pool_config');
            $newContent = $this->substituteMaxChildren($opened['content'], $proposed);

            if ($newContent === $opened['content']) {
                return FixResult::failure(__('Could not locate pm.max_children in the pool config — refusing to write.'));
            }

            $saveResult = $this->editor->saveTarget($server, $phpVersion, 'pool_config', $newContent);
        } catch (\Throwable $e) {
            return FixResult::failure(__('Validation or write failed: :err', ['err' => $e->getMessage()]), $backupPath);
        }

        $this->stampBackupPath($finding, $backupPath, $proposed, $current);

        return FixResult::success(sprintf(
            "pm.max_children: %d → %d\nbackup: %s\n%s",
            $current,
            $proposed,
            $backupPath,
            $saveResult['output'] ?? ''
        ));
    }

    /**
     * @param  array<string, mixed> $params
     */
    private function computeProposedMaxChildren(int $current, int $totalRamMb, array $params): int
    {
        $ramShare = (float) ($params['ram_share_pct'] ?? 60);
        $ramShare = max(20, min(85, $ramShare)) / 100;

        $perWorkerMb = (int) ($params['per_worker_mb'] ?? 30);
        $perWorkerMb = max(10, min(256, $perWorkerMb));

        $cap = (int) ($params['max_children_cap'] ?? 256);
        $cap = max(8, min(2048, $cap));

        $raw = (int) floor(($totalRamMb * $ramShare) / $perWorkerMb);
        $proposed = min($cap, max($current + 5, $raw));

        return $proposed;
    }

    private function resolveTotalRamMb(Server $server): ?int
    {
        // Prefer fresh metric snapshots (mem_total_kb), fall back to provisioning summary.
        $latest = ServerMetricSnapshot::query()
            ->where('server_id', $server->id)
            ->whereNotNull('captured_at')
            ->orderByDesc('captured_at')
            ->first();
        $kb = is_array($latest->payload ?? null) ? ($latest->payload['mem_total_kb'] ?? null) : null;
        if (is_numeric($kb) && (int) $kb > 0) {
            return (int) round(((int) $kb) / 1024);
        }

        // Stack-summary path — not a public accessor today, so we re-derive using a small lookup.
        $tags = ServerInstalledServices::tagsFor($server);
        if (array_key_exists('unknown', $tags)) {
            return null;
        }

        // ServerInstalledServices doesn't expose total_memory_mb today; if the metrics
        // snapshot is missing (fresh server with no sample yet) we refuse rather than guess.
        return null;
    }

    private function substituteMaxChildren(string $content, int $newValue): string
    {
        $pattern = '/^(\s*)pm\.max_children\s*=\s*\d+\s*$/m';

        return preg_replace($pattern, '$1pm.max_children = '.$newValue, $content) ?? $content;
    }

    /**
     * @param  array<string, mixed> $params
     */
    public function revert(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult
    {
        $backupPath = $finding->meta['backup_path'] ?? null;
        $phpVersion = $finding->meta['signal']['php_version'] ?? null;

        if (! is_string($backupPath) || $backupPath === '') {
            return FixResult::failure(__('No backup recorded for this finding — nothing to revert.'));
        }
        if (! is_string($phpVersion) || ! preg_match('/^\d+\.\d+$/', $phpVersion)) {
            return FixResult::failure(__('PHP version is missing from the finding signal — cannot reload FPM safely.'));
        }

        $poolPath = "/etc/php/{$phpVersion}/fpm/pool.d/www.conf";

        // Restore the file from backup, validate via php-fpm -tt, then reload. We re-use the
        // editor's saveTarget by reading the backup contents and feeding them through — that
        // way we get the same validation safety net as the original apply.
        $readScript = sprintf(
            'set -eu; if [ ! -f %s ]; then echo "missing-backup"; exit 1; fi; cat %1$s',
            escapeshellarg($backupPath),
        );

        try {
            $out = $this->remote->runInlineBash($server, 'insight-revert-fpm-read-backup', $readScript, 30, true);
            $backupContent = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            return FixResult::failure(__('Could not read backup :path: :err', ['path' => $backupPath, 'err' => $e->getMessage()]));
        }

        if (str_contains($backupContent, 'missing-backup')) {
            return FixResult::failure(__('Backup file no longer exists at :path.', ['path' => $backupPath]));
        }

        try {
            $this->editor->saveTarget($server, $phpVersion, 'pool_config', $backupContent);
        } catch (\Throwable $e) {
            return FixResult::failure(__('Validation or write failed during revert: :err', ['err' => $e->getMessage()]));
        }

        $meta = ($finding->meta );
        unset($meta['backup_path']);
        $meta['revert_applied_at'] = now()->toIso8601String();
        $meta['revert_pool_path'] = $poolPath;
        $finding->forceFill(['meta' => $meta])->save();

        return FixResult::success(__('Restored :path from backup and reloaded PHP-FPM.', ['path' => $poolPath]));
    }

    private function stampBackupPath(InsightFinding $finding, string $backupPath, int $newValue, int $previousValue): void
    {
        $meta = ($finding->meta );
        $meta['backup_path'] = $backupPath;
        $meta['fix_change'] = [
            'pm_max_children_before' => $previousValue,
            'pm_max_children_after' => $newValue,
        ];
        $finding->forceFill(['meta' => $meta])->save();
    }
}
