<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SiteDeployment;
use App\Services\SshConnection;
use App\Support\Sites\BindingReachability;

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
