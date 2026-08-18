<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerFirewallRule;
use Illuminate\Support\Facades\Log;

/**
 * Open/close a single UFW port that dply owns on behalf of a feature.
 *
 * Features that expose a service (database engine remote access, cache
 * exposure, the log aggregator's mTLS listener) all need the same three
 * things: a persisted {@see ServerFirewallRule} so the workspace Firewall tab
 * shows what is open and why, the rule pushed to the box over SSH, and the
 * whole thing torn down again when the feature is turned off.
 *
 * Doing that by hand is how ports drift. `InstallLogAggregatorJob` shipped with
 * no firewall wiring at all, so the aggregator installed and then sat
 * unreachable; `ToggleDatabaseEngineRemoteAccessJob` hand-rolled the same
 * reconciliation inline. This is that logic in one place.
 *
 * Reconciliation is keyed on a caller-supplied TAG, not on the port: the tag is
 * what makes `open()` idempotent (re-opening with a new CIDR edits the existing
 * rule instead of stacking duplicates) and what lets `close()` find and remove
 * exactly the rule this feature owns, leaving operator-authored rules alone.
 *
 * Host-side failures are logged and swallowed rather than thrown — matching the
 * existing callers. The DB row is the source of truth the Firewall tab renders
 * and `ServerFirewallProvisioner::apply()` re-syncs, so a transient SSH failure
 * leaves a recoverable state rather than a half-applied one.
 */
class ManagedFirewallPort
{
    public function __construct(private readonly ServerFirewallProvisioner $firewall) {}

    /**
     * Ensure `$port` is open to `$source`, tracked under `$tag`.
     *
     * @param  string  $tag  Stable per-feature identity, e.g. 'dply-logs-aggregator'.
     * @param  string  $name  Human label for the Firewall tab.
     * @param  list<string>  $extraTags  Grouping tags (e.g. 'dply-database') alongside $tag.
     */
    public function open(
        Server $server,
        string $tag,
        int $port,
        string $source,
        string $name,
        array $extraTags = [],
        string $protocol = 'tcp',
    ): ?ServerFirewallRule {
        if ($port <= 0 || $source === '') {
            return null;
        }

        $rule = $this->find($server, $tag);

        if ($rule === null) {
            $rule = ServerFirewallRule::query()->create([
                'server_id' => $server->id,
                'name' => $name,
                'port' => $port,
                'protocol' => $protocol,
                'source' => $source,
                'action' => 'allow',
                'enabled' => true,
                'sort_order' => (int) (ServerFirewallRule::query()
                    ->where('server_id', $server->id)
                    ->max('sort_order') ?? 0) + 1,
                'tags' => array_values(array_unique([...$extraTags, $tag])),
            ]);

            $this->push($server, $rule, $tag, 'create');

            return $rule;
        }

        // Already tracked. Only touch the host when something it cares about
        // actually moved — re-pushing an unchanged rule is a wasted SSH round
        // trip on every reconcile.
        $changed = $rule->source !== $source
            || (int) $rule->port !== $port
            || $rule->protocol !== $protocol
            || $rule->enabled !== true;

        if (! $changed) {
            return $rule;
        }

        $rule->update([
            'source' => $source,
            'port' => $port,
            'protocol' => $protocol,
            'enabled' => true,
        ]);

        $this->push($server, $rule, $tag, 'update');

        return $rule;
    }

    /**
     * Remove the rule this feature owns, on the host and in the database.
     * No-op when nothing is tracked, so it is safe to call unconditionally.
     */
    public function close(Server $server, string $tag): void
    {
        $rule = $this->find($server, $tag);

        if ($rule === null) {
            return;
        }

        try {
            $this->firewall->removeFromHost($server, $rule);
        } catch (\Throwable $e) {
            // Drop the row regardless: leaving it behind would show the port as
            // open in the UI when the feature is off. `apply()` reconciles the
            // host from the full rule set and will drop the stale UFW entry.
            Log::warning('ManagedFirewallPort: host removal failed; deleting rule anyway', [
                'server_id' => $server->id,
                'tag' => $tag,
                'error' => $e->getMessage(),
            ]);
        }

        $rule->delete();
    }

    /**
     * Open one port to MANY sources, as a group.
     *
     * A UFW rule admits a single source, so "let these four servers reach
     * ClickHouse" is four rules, not one — and collapsing them to a covering
     * CIDR would silently admit every other host in that range. Each source gets
     * its own rule tagged `{$groupTag}:{key}`, plus the shared `$groupTag` so
     * {@see closeAll} can find the whole set. Sources that disappear from
     * `$sourcesByKey` are revoked, which is what makes re-submitting a changed
     * selection behave like an edit rather than an append.
     *
     * @param  array<string, string>  $sourcesByKey  Stable key (e.g. server id) => CIDR.
     * @param  callable(string, string): string  $nameFor  (key, cidr) => rule name.
     * @param  list<string>  $extraTags
     * @return int Number of sources currently open.
     */
    public function openGroup(
        Server $server,
        string $groupTag,
        int $port,
        array $sourcesByKey,
        callable $nameFor,
        array $extraTags = [],
        string $protocol = 'tcp',
    ): int {
        foreach ($this->findAll($server, $groupTag) as $rule) {
            $key = $this->keyFromTags($rule, $groupTag);

            if ($key === null || ! array_key_exists($key, $sourcesByKey)) {
                $this->close($server, $groupTag.':'.($key ?? ''));
            }
        }

        foreach ($sourcesByKey as $key => $source) {
            $this->open(
                server: $server,
                tag: $groupTag.':'.$key,
                port: $port,
                source: $source,
                name: $nameFor((string) $key, $source),
                extraTags: [...$extraTags, $groupTag],
                protocol: $protocol,
            );
        }

        return count($sourcesByKey);
    }

    /**
     * Revoke every rule in a group. Used when a feature is switched off wholesale.
     */
    public function closeAll(Server $server, string $groupTag): void
    {
        foreach ($this->findAll($server, $groupTag) as $rule) {
            try {
                $this->firewall->removeFromHost($server, $rule);
            } catch (\Throwable $e) {
                Log::warning('ManagedFirewallPort: host removal failed; deleting rule anyway', [
                    'server_id' => $server->id,
                    'group_tag' => $groupTag,
                    'error' => $e->getMessage(),
                ]);
            }

            $rule->delete();
        }
    }

    /**
     * Remove a legacy SINGLE rule left under a group tag.
     *
     * A feature that used to open one broad rule (`open()`) and now opens a set
     * (`openGroup()`) leaves the old rule behind: it carries the group tag, so it
     * still holds the port open, but it is not a group member and openGroup's
     * diff never sees it. close() cannot be used for this — group members carry
     * the group tag too, so it would delete one of them at random.
     *
     * Identify the stragglers precisely: tagged with the group, but with no
     * `{$groupTag}:{key}` member tag.
     */
    public function closeUngrouped(Server $server, string $groupTag): int
    {
        $removed = 0;

        foreach ($this->findAll($server, $groupTag) as $rule) {
            if ($this->keyFromTags($rule, $groupTag) !== null) {
                continue; // a real group member
            }

            try {
                $this->firewall->removeFromHost($server, $rule);
            } catch (\Throwable $e) {
                Log::warning('ManagedFirewallPort: host removal failed for legacy rule; deleting anyway', [
                    'server_id' => $server->id,
                    'group_tag' => $groupTag,
                    'error' => $e->getMessage(),
                ]);
            }

            $rule->delete();
            $removed++;
        }

        return $removed;
    }

    public function find(Server $server, string $tag): ?ServerFirewallRule
    {
        return ServerFirewallRule::query()
            ->where('server_id', $server->id)
            ->whereJsonContains('tags', $tag)
            ->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, ServerFirewallRule>
     */
    public function findAll(Server $server, string $tag): \Illuminate\Support\Collection
    {
        return ServerFirewallRule::query()
            ->where('server_id', $server->id)
            ->whereJsonContains('tags', $tag)
            ->get();
    }

    /**
     * Recover the per-source key from a group member's `{$groupTag}:{key}` tag.
     */
    private function keyFromTags(ServerFirewallRule $rule, string $groupTag): ?string
    {
        foreach ((array) ($rule->tags ?? []) as $tag) {
            if (is_string($tag) && str_starts_with($tag, $groupTag.':')) {
                return substr($tag, strlen($groupTag) + 1);
            }
        }

        return null;
    }

    private function push(Server $server, ServerFirewallRule $rule, string $tag, string $op): void
    {
        try {
            $this->firewall->applyRule($server, $rule);
        } catch (\Throwable $e) {
            Log::warning('ManagedFirewallPort: applyRule failed', [
                'server_id' => $server->id,
                'tag' => $tag,
                'op' => $op,
                'port' => $rule->port,
                'source' => $rule->source,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
