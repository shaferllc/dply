<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\User;
use App\Services\Notifications\ServerHealthNotificationDispatcher;
use App\Support\ServerHealthNotificationKeys;

/**
 * Evaluates the {@see ServerHealthCockpit} for a server, persists the posture we
 * last notified on into the server's `meta`, and fires transition-aware
 * notifications when the overall posture worsens into a warning / critical state
 * or recovers to healthy.
 *
 * The cockpit is a pure DB rollup (guest metrics, releases, deploys, certs,
 * daemons) — no SSH — so this is cheap to run on the fleet health cadence. Hooked
 * into {@see \App\Jobs\CheckServerHealthJob}. Mirrors the notify half of
 * {@see ServerSecurityDigestScanner}.
 */
final class ServerHealthNotifier
{
    public function __construct(
        private readonly ServerHealthCockpit $cockpit,
        private readonly ServerHealthNotificationDispatcher $dispatcher,
    ) {}

    public function evaluateAndNotify(Server $server, ?User $actor = null): void
    {
        $prior = $this->priorOverall($server);

        $report = $this->cockpit->forServer($server);
        $overall = (string) ($report['overall'] ?? 'ok');

        // Stamp the posture we evaluated regardless of whether it transitioned,
        // so the next run compares against the freshest baseline.
        $meta = is_array($server->meta) ? $server->meta : [];
        $meta[ServerHealthNotificationKeys::NOTIFIED_OVERALL_KEY] = $overall;
        $server->update(['meta' => $meta]);

        $kind = $this->transitionKind($prior, $overall);
        if ($kind === null) {
            return;
        }

        $this->dispatcher->notify(
            $server,
            $kind,
            $this->detailLines($kind, $report),
            $actor,
            ['overall' => $overall, 'previous_overall' => $prior],
        );
    }

    private function priorOverall(Server $server): ?string
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $value = $meta[ServerHealthNotificationKeys::NOTIFIED_OVERALL_KEY] ?? null;

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
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function detailLines(string $kind, array $report): array
    {
        if ($kind === 'posture_cleared') {
            return [__('Health signals look calm again — capacity, releases, certs, and daemons are nominal.')];
        }

        $severity = $kind === 'critical_finding' ? 'critical' : 'warning';
        $lines = [];

        foreach (($report['alerts'] ?? []) as $alert) {
            if (($alert['severity'] ?? null) === $severity && count($lines) < 4) {
                $lines[] = '• '.(string) ($alert['title'] ?? '');
            }
        }

        return $lines;
    }
}
