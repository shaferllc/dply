<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\User;
use App\Services\Notifications\ServerReleaseHygieneNotificationDispatcher;
use App\Services\SshConnection;
use RuntimeException;

/**
 * Runs the release hygiene SSH scan (release folders, Laravel log sizes, failed
 * queue jobs), persists the snapshot into the server's `meta`, and fires
 * transition-aware notifications when the overall posture worsens into a warning /
 * critical state or recovers to healthy.
 *
 * Shared by the interactive "Scan disk" button
 * ({@see \App\Livewire\Servers\Concerns\RunsServerReleaseHygieneScan}) and the daily
 * fleet sweep ({@see \App\Jobs\RunServerReleaseHygieneScanJob}) so both run one code path.
 * Mirrors {@see ServerSecurityDigestScanner}.
 */
final class ServerReleaseHygieneScanner
{
    /** Sibling meta key holding the last posture we notified on (avoids re-alert spam). */
    public const NOTIFIED_OVERALL_KEY = 'release_hygiene_notified_overall';

    public function __construct(
        private readonly ServerReleaseHygieneScript $script,
        private readonly ServerReleaseHygiene $hygiene,
        private readonly ServerReleaseHygieneNotificationDispatcher $dispatcher,
    ) {}

    /**
     * Connect over SSH (root → deploy fallback), run the hygiene script, persist the
     * parsed snapshot, and notify on posture transitions.
     *
     * @param  callable(string):mixed|null  $onChunk  Streamed stdout sink (UI live output); null for headless runs.
     *
     * @throws RuntimeException When no candidate login could complete the scan.
     */
    public function scanAndNotify(Server $server, ?User $actor = null, ?callable $onChunk = null): void
    {
        $output = $this->runScan($server, $onChunk);

        $prior = $this->priorOverall($server);

        $meta = $this->script->parse($output, is_array($server->meta) ? $server->meta : []);

        // Compute the new overall against the freshly-parsed snapshot (in memory)
        // before persisting, then stamp the notified posture into the same write.
        $server->setAttribute('meta', $meta);
        $report = $this->hygiene->forServer($server);
        $overall = (string) ($report['overall'] ?? 'ok');

        $meta[self::NOTIFIED_OVERALL_KEY] = $overall;
        $server->update(['meta' => $meta]);
        $server->refresh();

        $kind = $this->transitionKind($prior, $overall);
        if ($kind !== null) {
            $this->dispatcher->notify(
                $server,
                $kind,
                $this->detailLines($kind, $report),
                $actor,
                ['overall' => $overall, 'previous_overall' => $prior],
            );
        }
    }

    /**
     * @param  callable(string):mixed|null  $onChunk
     */
    private function runScan(Server $server, ?callable $onChunk): string
    {
        $script = $this->script->build($this->siteScanTargets($server));
        $wrapped = '/bin/sh -c '.escapeshellarg($script);
        $timeout = max(60, (int) config('server_settings.inventory_ssh_timeout_basic', 120));
        $deploy = trim((string) $server->ssh_user) ?: 'root';
        $wantRoot = (bool) config('server_settings.inventory_use_root_ssh', true);
        $fallback = (bool) config('server_settings.inventory_fallback_to_deploy_user_ssh', true);
        $candidates = $wantRoot && $deploy !== 'root' ? array_filter(['root', $fallback ? $deploy : null]) : [$deploy];
        $candidates = array_values(array_filter($candidates));

        $lastError = null;
        foreach ($candidates as $loginUser) {
            try {
                $ssh = new SshConnection($server, $loginUser);
                $out = trim($ssh->execWithCallback(
                    $wrapped,
                    static fn (string $chunk): mixed => $onChunk !== null ? $onChunk($chunk) : null,
                    $timeout,
                ));
                $ssh->disconnect();

                return $out;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw new RuntimeException(
            $lastError?->getMessage() ?: __('SSH connection failed for hygiene scan.'),
            0,
            $lastError,
        );
    }

    /**
     * Per-site scan targets (slug, repository path, keep count, atomic flag) handed
     * to {@see ServerReleaseHygieneScript::build()}.
     *
     * @return list<array{slug: string, path: string, keep: int, atomic: bool}>
     */
    private function siteScanTargets(Server $server): array
    {
        return $server->sites()
            ->get(['slug', 'repository_path', 'deploy_strategy', 'releases_to_keep'])
            ->map(fn ($site): array => [
                'slug' => (string) $site->slug,
                'path' => $site->effectiveRepositoryPath(),
                'keep' => max(1, min(50, (int) ($site->releases_to_keep ?? 5))),
                'atomic' => $site->isAtomicDeploys(),
            ])
            ->values()
            ->all();
    }

    private function priorOverall(Server $server): ?string
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $value = $meta[self::NOTIFIED_OVERALL_KEY] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Returns the notify kind for a posture transition, or null when nothing
     * actionable changed. Only escalations (into warning / critical) and full
     * recoveries are announced; sideways/same-level moves stay quiet.
     */
    private function transitionKind(?string $prior, string $overall): ?string
    {
        if ($overall === 'critical' && $prior !== 'critical') {
            return 'critical_finding';
        }

        if ($overall === 'warning' && ! in_array($prior, ['warning', 'critical'], true)) {
            return 'warning_finding';
        }

        if ($overall === 'ok' && in_array($prior, ['warning', 'critical'], true)) {
            return 'posture_cleared';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function detailLines(string $kind, array $report): array
    {
        if ($kind === 'posture_cleared') {
            return [__('Release, log, and disk pressure is back within healthy thresholds.')];
        }

        $severity = $kind === 'critical_finding' ? 'critical' : 'warning';
        $lines = [];

        foreach (($report['alerts'] ?? []) as $alert) {
            if (($alert['severity'] ?? null) === $severity && count($lines) < 4) {
                $lines[] = '• '.(string) ($alert['title'] ?? '');
            }
        }

        $disk = $report['disk']['pct'] ?? null;
        if ($disk !== null) {
            $lines[] = __('Disk usage: :pct%', ['pct' => number_format((float) $disk, 0)]);
        }

        $failed = (int) ($report['failed_jobs']['total'] ?? 0);
        if ($failed > 0) {
            $lines[] = __('Failed queue jobs: :count', ['count' => $failed]);
        }

        return $lines;
    }
}
