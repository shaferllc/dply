<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SiteDeployment;
use App\Services\SshConnection;
use App\Support\Sites\BindingReachability;
use App\Support\Sites\SiteFixers;

/**
 * Pre-cutover gate: verifies that every networked resource binding a site
 * depends on (database, redis, storage, mail, broadcasting, logging) is
 * actually reachable from the server that will dial it at runtime, BEFORE the
 * atomic symlink flips.
 *
 * A deploy can otherwise report "success" while the live app immediately 500s
 * because its database/cache/object-store can't be reached (wrong host/port,
 * firewall, service not listening on the private interface). This runs the same
 * pure-bash TCP probe the on-demand Resources check uses ({@see
 * ValidateSiteBindingsReachableJob}) over the deployer's already-open SSH
 * connection, then:
 *
 *   - records a `resources` phase on the deployment so each binding shows on the
 *     deploy timeline,
 *   - refreshes each binding's `config.connectivity` + `last_error` so the
 *     Resources card badge stays in step,
 *   - THROWS when any CRITICAL binding is unreachable — the deployer never
 *     reaches the cutover, the prior release keeps serving, and nothing broken
 *     goes live.
 *
 * Auxiliary networked bindings (broadcasting, logging) are probed too but only
 * warn: a flaky log drain shouldn't block a deploy. TCP-level only — it answers
 * "can the box open a socket to the service?"; auth/protocol checks are a future
 * refinement (matching the existing reachability check's scope).
 */
final class DeployResourceVerifier
{
    /**
     * Networked binding types whose unreachability HARD-FAILS the deploy — the
     * data-plane resources the app can't boot or serve without. Driver-only
     * types (cache/queue/session) ride the redis/database connection they
     * reference, so they're covered transitively by that binding's probe.
     *
     * @var list<string>
     */
    private const CRITICAL = ['database', 'redis', 'storage', 'mail'];

    /**
     * Probe every networked binding and return log lines to append to the deploy
     * log. Records the `resources` phase on $deployment when given.
     *
     * @throws \RuntimeException when a critical binding is unreachable
     */
    public function verify(Site $site, SshConnection $ssh, ?SiteDeployment $deployment = null): string
    {
        // Escape hatch: the gate is on by default, but an operator can disable it
        // per-site (meta.deploy_resource_verify = false) if a probe ever
        // misjudges a resource that the app can in fact reach.
        $meta = ($site->meta );
        if (($meta['deploy_resource_verify'] ?? true) === false) {
            return "\n[dply] RESOURCES → verification disabled for this site (deploy_resource_verify=false); skipping\n";
        }

        // Resolve every networked binding to a dialable host:port up front.
        $targets = [];
        foreach ($site->bindings as $binding) {
            $target = BindingReachability::target($binding);
            if ($target !== null) {
                $targets[] = [$binding, $target['host'], $target['port']];
            }
        }

        if ($targets === []) {
            return "\n[dply] RESOURCES → no networked resources to verify\n";
        }

        $log = "\n--- verify resources (pre-cutover) ---\n";
        $log .= sprintf("Probing %d resource binding(s) for reachability from %s before cutover\n",
            count($targets), (string) ($site->server->name ?? 'the server'));

        $steps = [];
        $criticalFailures = [];

        foreach ($targets as [$binding, $host, $port]) {
            /** @var SiteBinding $binding */
            $label = $binding->name ?: $binding->type;
            $critical = in_array($binding->type, self::CRITICAL, true);
            $start = microtime(true);

            // Pure-bash TCP probe: no client binary needed, works for any engine.
            $cmd = sprintf(
                "timeout 5 bash -c '</dev/tcp/%s/%d' >/dev/null 2>&1 && echo DPLY_PROBE_OK || echo DPLY_PROBE_FAIL",
                $host,
                $port,
            );
            $out = (string) $ssh->exec($cmd, 15);
            $reachable = str_contains($out, 'DPLY_PROBE_OK');
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            $detail = $reachable
                ? null
                : sprintf('%s could not open a TCP socket to %s:%d.', (string) ($site->server->name ?? 'The server'), $host, $port);
            $this->recordBinding($binding, $reachable, $detail, $host, $port);

            if ($reachable) {
                $log .= sprintf("✓ %s (%s) — reachable at %s:%d\n", $label, $binding->type, $host, $port);
            } elseif ($critical) {
                $log .= sprintf("✗ %s (%s) — UNREACHABLE at %s:%d — blocking cutover\n", $label, $binding->type, $host, $port);
                $criticalFailures[] = sprintf('%s (%s) at %s:%d', $label, $binding->type, $host, $port);
            } else {
                $log .= sprintf("⚠ %s (%s) — unreachable at %s:%d (non-blocking)\n", $label, $binding->type, $host, $port);
            }

            // Critical-unreachable → ok=false: the phase reads red AND the deploy
            // throws below. Auxiliary-unreachable → ok=true + skipped=true: an
            // amber, non-failing marker on the timeline; the persistent red badge
            // lives on the Resources card via recordBinding(), so the phase stays
            // green and the deploy proceeds.
            $auxWarn = ! $reachable && ! $critical;
            $steps[] = [
                'step_id' => 'resource_'.$binding->id,
                'step_type' => 'resource',
                'command' => sprintf('%s → %s:%d', $label, $host, $port),
                'ok' => $reachable || ! $critical,
                'skipped' => $auxWarn,
                'output' => $this->stepOutput($label, $binding->type, $host, $port, $reachable, $critical),
                'duration_ms' => $durationMs,
            ];
        }

        // A redis binding points the app at phpredis (REDIS_CLIENT=phpredis, and
        // often cache/session/queue → redis). If the box's PHP lacks the `redis`
        // extension the live app boots straight into a 500 (`Class "Redis" not
        // found`) — exactly the kind of "deploy succeeds, app is down" failure
        // this gate exists to stop. So when a redis binding is present we verify
        // (and idempotently install) the extension here over the already-open SSH
        // connection, rather than as a standalone console-action banner. Cheap
        // `php -m` no-op on a cleanly provisioned box; only touches apt when the
        // extension is genuinely missing.
        if ($site->bindings->contains(static fn (SiteBinding $b): bool => $b->type === 'redis')) {
            [$step, $stepLog, $failure] = $this->verifyPhpRedisExtension($site, $ssh);
            $log .= $stepLog;
            if ($step !== null) {
                $steps[] = $step;
            }
            if ($failure !== null) {
                $criticalFailures[] = $failure;
            }
        }

        $deployment?->recordPhaseResults('resources', $steps);

        if ($criticalFailures !== []) {
            throw new \RuntimeException(
                __('Deploy blocked before cutover — :n critical resource(s) unreachable from the server: :list. The previous release is still live and nothing changed. Make sure each service is listening, bound to the private interface, and allows this server\'s IP, then redeploy.', [
                    'n' => count($criticalFailures),
                    'list' => implode('; ', $criticalFailures),
                ])."\n".$log
            );
        }

        $log .= sprintf("[dply] RESOURCES → %d reachable, %d unreachable (no critical failures)\n",
            count($targets) - $this->unreachableCount($steps), $this->unreachableCount($steps));

        return $log;
    }

    /**
     * Guarantee the PHP `redis` client extension is present on the box when the
     * site has a redis binding. One SSH round-trip that (1) skips silently when
     * there's no server-side PHP (static/headless host), (2) no-ops when the
     * extension is already loaded — the common case on a cleanly provisioned box,
     * else (3) installs it via the audited {@see SiteFixers} `install_php_redis`
     * command and re-checks. Verdict is driven by markers, not exit codes
     * ({@see SshConnection::exec()} never throws on non-zero).
     *
     * @return array{0: array<string, mixed>|null, 1: string, 2: string|null}
     *   [phase step (null when not applicable), log lines, critical-failure label]
     */
    private function verifyPhpRedisExtension(Site $site, SshConnection $ssh): array
    {
        $start = microtime(true);
        $install = (string) SiteFixers::spec('install_php_redis')['command'];

        $script = implode("\n", [
            'if ! command -v php >/dev/null 2>&1; then echo DPLY_NO_PHP; exit 0; fi',
            "if php -m 2>/dev/null | grep -qi '^redis$'; then echo DPLY_HAVE_REDIS; exit 0; fi",
            'echo DPLY_INSTALLING',
            $install,
            "php -m 2>/dev/null | grep -qi '^redis$' && echo DPLY_REDIS_OK || echo DPLY_REDIS_MISSING",
        ]);

        // Long timeout: the install path may build from PECL behind an apt lock.
        $out = (string) $ssh->exec('sudo -n bash -lc '.escapeshellarg($script), 600);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        // No server-side PHP (static/headless host) — the extension is moot.
        if (str_contains($out, 'DPLY_NO_PHP')) {
            return [null, "· PHP redis extension — no server-side PHP on this host; skipping\n", null];
        }

        $alreadyHad = str_contains($out, 'DPLY_HAVE_REDIS');
        $installed = str_contains($out, 'DPLY_REDIS_OK');
        $present = $alreadyHad || $installed;

        $detail = match (true) {
            $alreadyHad => 'The PHP redis extension is already installed.',
            $installed => 'The PHP redis extension was missing and has been installed (PHP-FPM reloaded).',
            default => 'The PHP redis extension is missing and could not be installed — the app will 500 with '
                .'`Class "Redis" not found` once it dials phpredis. Blocking cutover so the previous release keeps serving.',
        };

        $log = $present
            ? sprintf("✓ PHP redis extension — %s\n", $alreadyHad ? 'present' : 'installed')
            : "✗ PHP redis extension — MISSING and install failed — blocking cutover\n";

        $step = [
            'step_id' => 'php_redis_ext',
            'step_type' => 'resource',
            'command' => 'PHP redis extension',
            'ok' => $present,
            'skipped' => false,
            'output' => $detail,
            'duration_ms' => $durationMs,
        ];

        return [$step, $log, $present ? null : 'PHP redis extension (missing, install failed)'];
    }

    /** Human-readable per-step detail for the timeline output drawer. */
    private function stepOutput(string $label, string $type, string $host, int $port, bool $reachable, bool $critical): string
    {
        if ($reachable) {
            return sprintf("%s (%s) reachable at %s:%d.", $label, $type, $host, $port);
        }

        $kind = $critical ? 'CRITICAL — blocks the deploy' : 'auxiliary — non-blocking warning';

        return sprintf(
            "%s (%s) UNREACHABLE at %s:%d [%s].\n".
            "The server could not open a TCP socket to this address. Usual causes: the\n".
            "service isn't listening on that address/port, isn't bound to the private\n".
            "interface, is firewalled in-host, or doesn't allow this server's private IP.",
            $label, $type, $host, $port, $kind
        );
    }

    /** @param list<array<string, mixed>> $steps */
    private function unreachableCount(array $steps): int
    {
        // Reachable steps record ok=true && skipped=false; both failure kinds set
        // ok=false (critical) or skipped=true (aux warn).
        return count(array_filter($steps, static fn (array $s): bool => ($s['ok'] ?? false) !== true || ($s['skipped'] ?? false) === true));
    }

    /**
     * Mirror the on-demand reachability check's record shape so the Resources
     * card badge ("Reachable / Unreachable / Not checked") reflects the deploy.
     */
    private function recordBinding(SiteBinding $binding, bool $ok, ?string $error, string $host, int $port): void
    {
        $config = ($binding->config );
        $config['connectivity'] = [
            'ok' => $ok,
            'checked_at' => now()->toIso8601String(),
            'detail' => $error,
            'host' => $host,
            'port' => $port,
        ];
        $binding->forceFill([
            'config' => $config,
            'last_error' => $ok ? null : $error,
        ])->save();
    }
}
