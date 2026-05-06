<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Jobs\ApplyFirewallJob;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Str;

/**
 * One-click "expose this cache instance to the network" flow.
 *
 * Default cache installs bind to 127.0.0.1 — invisible to the network. Operators that need
 * cross-server access (app on box A → Redis on box B in a VPC) want four things to happen
 * in lockstep:
 *   1. Rewrite the engine's `bind` to 0.0.0.0 (or back to 127.0.0.1 for lockdown).
 *   2. Restart the engine and verify it accepts a connection on the new bind.
 *   3. Create / remove a panel-tracked firewall rule allowing the cache port from a trusted
 *      source CIDR.
 *   4. Dispatch {@see ApplyFirewallJob} so the host firewall picks up the new rule.
 *
 * The firewall rule is tagged with a stable identifier (`cache:{engine}:{name}:{port}`) so
 * lockdown can find and remove it without us having to track its id elsewhere.
 *
 * Redis-family only for now — Memcached and Dragonfly use different config formats and can
 * be added in a follow-up. Caller is expected to gate on engineSupportsAuth() (or similar)
 * before invoking expose().
 */
class CacheServiceNetworkExposure
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * Tag identifying the firewall rule managed by this exposure flow. Searching for a rule
     * with this tag is how we know whether a given cache instance is currently exposed.
     */
    public static function firewallRuleTag(ServerCacheService $row): string
    {
        return sprintf('cache:%s:%s:%d', $row->engine, $row->name, (int) $row->port);
    }

    /**
     * True when a panel-tracked firewall rule exists for this cache instance — i.e. the
     * operator has already exposed it. The actual on-host bind state could be different
     * (drift), but the panel is the source of truth for "what we intend".
     */
    public function isExposed(ServerCacheService $row): bool
    {
        return $this->findManagedRule($row) !== null;
    }

    /**
     * The firewall rule managed by this flow, or null if none has been created yet.
     */
    public function findManagedRule(ServerCacheService $row): ?ServerFirewallRule
    {
        $tag = self::firewallRuleTag($row);

        return ServerFirewallRule::query()
            ->where('server_id', $row->server_id)
            ->whereJsonContains('tags', $tag)
            ->first();
    }

    /**
     * Rewrite bind to 0.0.0.0, restart, verify, then create the firewall rule + queue apply.
     *
     * @throws \RuntimeException when the bind change fails or verify rejects the new state.
     * @throws \InvalidArgumentException when the engine isn't supported or the source CIDR is rejected.
     */
    public function expose(Server $server, ServerCacheService $row, string $sourceCidr, ?string $userId = null): void
    {
        $this->guardEngine($row);
        $sourceCidr = $this->guardSource($sourceCidr);

        $this->rewriteBind($server, $row, '0.0.0.0');

        $rule = $this->findManagedRule($row);
        if ($rule === null) {
            ServerFirewallRule::query()->create([
                'server_id' => $server->id,
                'name' => sprintf('Cache · %s (%s)', ucfirst($row->engine), $row->name),
                'port' => (int) $row->port,
                'protocol' => 'tcp',
                'source' => $sourceCidr,
                'action' => 'allow',
                'enabled' => true,
                'sort_order' => (int) (ServerFirewallRule::query()->where('server_id', $server->id)->max('sort_order') ?? 0) + 1,
                'tags' => ['dply-cache', self::firewallRuleTag($row)],
            ]);
        } else {
            // Re-exposing with a different CIDR: update the rule in place rather than creating
            // a duplicate. This is the path operators hit when they widen / narrow the source.
            $rule->update([
                'source' => $sourceCidr,
                'enabled' => true,
            ]);
        }

        $this->dispatchApply($server, $userId);
    }

    /**
     * Rewrite bind back to 127.0.0.1, restart, verify, then remove the firewall rule + queue
     * apply so the host stops accepting external connections to the cache port.
     */
    public function lockdown(Server $server, ServerCacheService $row, ?string $userId = null): void
    {
        $this->guardEngine($row);

        $this->rewriteBind($server, $row, '127.0.0.1 -::1');

        $rule = $this->findManagedRule($row);
        if ($rule !== null) {
            $rule->delete();
        }

        $this->dispatchApply($server, $userId);
    }

    /**
     * Build the bash that swaps the cache engine's `bind` directive, restarts the unit, and
     * verifies the new bind via CLI ping. On verify failure the prior config is restored and
     * the unit is restarted on the old bind — same pattern as {@see CacheServiceAuth}.
     */
    protected function rewriteBind(Server $server, ServerCacheService $row, string $bindValue): void
    {
        $configPath = CacheServiceInstallScripts::instanceConfigPath($row->engine, $row->name);
        $serviceUnit = CacheServiceInstallScripts::instanceServiceUnit($row->engine, $row->name);
        $cli = $this->cliForEngine($row->engine);

        $authProlog = '';
        $authFlag = '';
        if ($row->auth_password !== null && $row->auth_password !== '') {
            $b64 = base64_encode((string) $row->auth_password);
            $authProlog = "PASS_B64={$b64}\nPASS=\$(printf %s \"\$PASS_B64\" | base64 -d)\n";
            $authFlag = ' -a "$PASS"';
        }

        $script = <<<BASH
set -e
{$authProlog}BACKUP=/tmp/dply-cache.conf.bak.\$\$
cp -p {$configPath} \$BACKUP
sed -i.tmp '/^[[:space:]]*bind[[:space:]]/d' {$configPath}
rm -f {$configPath}.tmp
printf 'bind %s\\n' '{$bindValue}' >> {$configPath}
systemctl restart {$serviceUnit}
sleep 1
if ! {$cli}{$authFlag} -p {$row->port} ping >/dev/null 2>&1; then
    cp -p \$BACKUP {$configPath}
    systemctl restart {$serviceUnit} || true
    rm -f \$BACKUP
    echo "[dply] Bind change failed: engine did not respond after restart; reverted." >&2
    exit 2
fi
rm -f \$BACKUP
BASH;

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:bind-change:'.$row->engine,
            $script,
            timeoutSeconds: 60,
            asRoot: true,
        );

        if ($output->exitCode !== 0) {
            throw new \RuntimeException(trim($output->buffer) ?: 'Cache bind change failed.');
        }
    }

    private function guardEngine(ServerCacheService $row): void
    {
        if (! in_array($row->engine, ['redis', 'valkey', 'keydb'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Network exposure flow only supports Redis-family engines (redis/valkey/keydb), not [%s].',
                $row->engine,
            ));
        }
    }

    /**
     * Reject obvious foot-guns (`any`, empty, "0.0.0.0/0") so operators don't accidentally
     * expose a cache to the public internet from this affordance. They can still create such
     * a rule manually in the firewall workspace if they really want to.
     */
    private function guardSource(string $sourceCidr): string
    {
        $value = trim($sourceCidr);
        $lower = strtolower($value);
        if ($value === '' || in_array($lower, ['any', '0.0.0.0/0', '::/0'], true)) {
            throw new \InvalidArgumentException(__('Pick a specific CIDR (e.g. 10.0.0.0/8 for VPC peers). Exposing a cache to the public internet from this dialog is not allowed — add the rule manually if you really need it.'));
        }

        return $value;
    }

    private function cliForEngine(string $engine): string
    {
        return match ($engine) {
            'valkey' => 'valkey-cli',
            'keydb' => 'keydb-cli',
            default => 'redis-cli',
        };
    }

    /**
     * Kick off ApplyFirewallJob so the host UFW reflects the rule change. The job is idempotent
     * and uses the same queued+banner flow the firewall workspace renders.
     */
    private function dispatchApply(Server $server, ?string $userId): void
    {
        $statusKey = config('server_firewall.meta_apply_status_key');
        $current = (string) data_get($server->fresh()->meta ?? [], $statusKey);
        if (in_array($current, ['queued', 'running'], true)) {
            // Apply already in flight — let it finish; the new rule will be picked up by a
            // subsequent click.
            return;
        }

        $runId = (string) Str::ulid();
        $meta = $server->fresh()->meta ?? [];
        $meta[config('server_firewall.meta_apply_run_id_key')] = $runId;
        $meta[$statusKey] = 'queued';
        $meta[config('server_firewall.meta_apply_started_at_key')] = now()->toIso8601String();
        $meta[config('server_firewall.meta_apply_finished_at_key')] = null;
        $meta[config('server_firewall.meta_apply_error_key')] = null;
        $server->fresh()->update(['meta' => $meta]);

        ApplyFirewallJob::dispatch($server->id, $runId, $userId);
    }
}
