<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\RunServerSecurityDigestScanJob;
use App\Livewire\Servers\Concerns\RunsServerSecurityDigestScan;
use App\Models\Server;
use App\Models\User;
use App\Modules\Notifications\Services\ServerSecurityDigestNotificationDispatcher;
use App\Services\SshConnection;
use RuntimeException;

/**
 * Runs the security digest SSH scan, persists the snapshot into the server's
 * `meta`, and fires transition-aware notifications when the overall posture
 * worsens into a warning / critical state or recovers to healthy.
 *
 * Shared by the interactive "Refresh digest" button
 * ({@see RunsServerSecurityDigestScan}) and the daily
 * fleet sweep ({@see RunServerSecurityDigestScanJob}) so both run one code path.
 */
final class ServerSecurityDigestScanner
{
    /** Sibling meta key holding the last posture we notified on (avoids re-alert spam). */
    public const NOTIFIED_OVERALL_KEY = 'security_digest_notified_overall';

    public function __construct(
        private readonly ServerSecurityDigestScript $script,
        private readonly ServerSecurityDigest $digest,
        private readonly ServerSecurityDigestNotificationDispatcher $dispatcher,
    ) {}

    /**
     * Connect over SSH (root → deploy fallback), run the digest script, persist the
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
        $report = $this->digest->forServer($server);
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
        $script = $this->script->build();
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
            $lastError->getMessage() ?: __('SSH connection failed for security digest.'),
            0,
            $lastError,
        );
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

        if (in_array($overall, ['ok', 'info'], true) && in_array($prior, ['warning', 'critical'], true)) {
            return 'posture_cleared';
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $report
     * @return list<string>
     */
    private function detailLines(string $kind, array $report): array
    {
        if ($kind === 'posture_cleared') {
            return [__('SSH surface looks calm again — no failed/expiring security signals.')];
        }

        $severity = $kind === 'critical_finding' ? 'critical' : 'warning';
        $lines = [];

        foreach (($report['alerts'] ?? []) as $alert) {
            if (($alert['severity'] ?? null) === $severity && count($lines) < 4) {
                $lines[] = '• '.(string) ($alert['title'] ?? '');
            }
        }

        $summary = $report['summary'] ?? [];
        if (isset($summary['auth_failed_total']) && $summary['auth_failed_total'] !== null) {
            $lines[] = __('auth.log failures: :count', ['count' => $summary['auth_failed_total']]);
        }

        $fail2ban = $report['fail2ban']['active'] ?? null;
        if (is_string($fail2ban) && $fail2ban !== '') {
            $lines[] = __('fail2ban: :state', ['state' => $fail2ban]);
        }

        return $lines;
    }
}
