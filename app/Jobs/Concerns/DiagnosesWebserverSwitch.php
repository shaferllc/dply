<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Server;
use App\Models\ServerWebserverAuditEvent;
use App\Services\RemoteCli\RiskLevel;
use App\Services\SshConnection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait DiagnosesWebserverSwitch
{


    /**
     * Block (up to ~10s) until the given TCP port has no listener — used
     * after `systemctl stop <old>` to make sure the kernel has released
     * the socket before we start the new webserver, otherwise the new
     * daemon refuses to bind with `address already in use`.
     *
     * Uses `ss -ltn` (one-shot, no DNS, IPv4+IPv6). If the loop times out
     * we fall through silently — the start attempt will surface its own
     * error via captureUnitDiagnostics() which is the user-friendly path.
     * As a last resort, kill any process still holding the port — this
     * covers detached child workers (OLS lsphpXX, Apache children) that
     * systemd didn't reap when the parent went inactive.
     */
    private function waitForPortFree(Server $server, SshConnection $ssh, int $port, string $stoppedUnit): void
    {
        $check = sprintf('ss -ltn -H "sport = :%d" 2>/dev/null | head -n 1', $port);
        $deadline = microtime(true) + 10.0;
        do {
            $out = trim($ssh->exec($check, 5));
            if ($out === '') {
                return; // socket is free
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        // Still holding the port. Try to SIGTERM whatever owns it; sleep
        // one more beat. If even that fails the start will report the
        // bind error with full diagnostics so the operator can intervene.
        $ssh->exec($this->privilegedCommand($server, sprintf('fuser -k -TERM %d/tcp 2>/dev/null || true', $port)), 5);
        usleep(500_000);
    }

    /**
     * Capture `systemctl status`, the recent journal, and the on-disk config(s)
     * for the failing webserver, so callers can embed the diagnostic in the
     * exception they raise. Best-effort: any failure to collect is folded into
     * the returned text rather than thrown, since the caller is already on a
     * failure path.
     *
     * Journal is filtered to `--since "-2min"` so we get only the latest failed
     * start, not stale 217/USER lines from prior attempts. `-x` (explanatory
     * blurbs) is dropped to keep the output compact enough to clear the banner /
     * UI truncation budget on the way to the actual error message.
     */
    private function captureUnitDiagnostics(Server $server, SshConnection $ssh, string $unit): string
    {
        $unitArg = escapeshellarg($unit);
        $configPaths = $this->diagnosticConfigPathsFor($this->target);

        $parts = [
            sprintf('echo "--- systemctl status %1$s ---"; systemctl status --no-pager --full %1$s 2>&1 | tail -n 30', $unitArg),
            sprintf('echo; echo "--- journalctl -eu %1$s (last 2 min) ---"; journalctl --no-pager -eu %1$s --since "-2min" 2>&1 | tail -n 120', $unitArg),
        ];
        foreach ($configPaths as $glob) {
            // The glob is intentionally NOT escapeshellarg'd — we want shell expansion
            // on the for-loop. The values come from a fixed match() table below, never
            // from user input, so injection is not a concern.
            $parts[] = sprintf(
                'echo; echo "--- %1$s ---"; for f in %1$s; do [ -e "$f" ] && { echo "# $f"; cat "$f"; echo; }; done',
                $glob,
            );
        }
        $script = '{ '.implode('; ', $parts).'; } 2>&1 || true';

        $out = $ssh->exec($this->privilegedCommand($server, $script), 30);

        // Cap so a runaway journal can't blow up the exception message / UI banner.
        $trimmed = trim((string) $out);

        return strlen($trimmed) > 8000 ? substr($trimmed, -8000) : $trimmed;
    }

    /**
     * On-disk paths (globs) worth dumping when a webserver fails to start.
     * Returns the main config plus per-site enabled configs for the target.
     */
    private function diagnosticConfigPathsFor(string $target): array
    {
        return match ($target) {
            'caddy' => ['/etc/caddy/Caddyfile', '/etc/caddy/sites-enabled/*.caddy'],
            'nginx' => ['/etc/nginx/nginx.conf', '/etc/nginx/sites-enabled/*'],
            'apache' => ['/etc/apache2/apache2.conf', '/etc/apache2/sites-enabled/*.conf'],
            'openlitespeed' => ['/usr/local/lsws/conf/httpd_config.conf', '/usr/local/lsws/conf/vhosts/*/vhconf.conf'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordAudit(
        Server $server,
        string $from,
        string $action,
        array $payload,
        float $startedAt,
        string $resultStatus = ServerWebserverAuditEvent::RESULT_FAILURE,
    ): void {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        ServerWebserverAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $this->userId,
            'action' => $action,
            'risk' => RiskLevel::MutatingRecoverable->value,
            'transport' => ServerWebserverAuditEvent::TRANSPORT_WEB,
            'summary' => __('Webserver switch from :from to :to (:status)', [
                'from' => $from !== '' ? $from : '(none)',
                'to' => $this->target,
                'status' => $resultStatus,
            ]),
            'payload' => array_merge($payload, [
                'from' => $from,
                'to' => $this->target,
                'duration_ms' => $durationMs,
            ]),
            'result_status' => $resultStatus,
        ]);
    }
}
