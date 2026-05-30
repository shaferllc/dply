<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Models\Server;
use App\Support\Servers\EdgeProxyWorkspaceViewData;
use App\Support\Servers\SystemdServiceStandbyReasonResolver;
use Carbon\CarbonImmutable;

/**
 * Default implementation of cache read/write semantics for
 * {@see EngineLiveStateProbe}. Concrete engine probes extend this and
 * implement `runFreshProbe()` only.
 *
 *   - forceFresh=false: try cached state from Server.meta; on miss run
 *     a fresh probe and cache it.
 *   - forceFresh=true: ALWAYS run a fresh probe, write through the cache.
 *
 * Probe failures are swallowed into an empty EngineLiveState with an
 * `errors` entry under engineSpecific — never thrown, since the UI must
 * always render something.
 */
abstract class AbstractEngineLiveStateProbe implements EngineLiveStateProbe
{
    abstract public function engineKey(): string;

    /**
     * Run a fresh probe (SSH / API / file read). Implementations parse
     * the raw output into the units arrays.
     *
     * Implementations should NEVER throw — catch their own errors and
     * fold them into engineSpecific['errors']. The base class will also
     * catch a runaway exception as a last-resort safety net.
     */
    abstract protected function runFreshProbe(Server $server): EngineLiveState;

    /**
     * Edge-proxy engines are mutually exclusive — when this engine is not
     * active, skip the admin API / stats socket and return standby copy
     * instead of a probe error.
     */
    protected function inactiveEdgeProxyLiveState(Server $server): ?EngineLiveState
    {
        if (! in_array($this->engineKey(), ['traefik', 'haproxy', 'envoy'], true)) {
            return null;
        }

        if ($server->edgeProxy() === $this->engineKey()) {
            return null;
        }

        $hint = app(SystemdServiceStandbyReasonResolver::class)
            ->inactiveEngineHint($server, $this->engineKey(), true);

        if ($hint === null) {
            $catalog = EdgeProxyWorkspaceViewData::edgeProxyCatalog();
            $label = $catalog[$this->engineKey()]['label'] ?? ucfirst($this->engineKey());
            $hint = __(':engine is not the active edge proxy on this server.', ['engine' => $label]);
        }

        return new EngineLiveState(
            engine: $this->engineKey(),
            capturedAt: CarbonImmutable::now(),
            isFresh: true,
            units: [],
            engineSpecific: [
                'standby' => true,
                'standby_reason' => $hint,
            ],
        );
    }

    public function probe(Server $server, bool $forceFresh = false): EngineLiveState
    {
        if (! $forceFresh) {
            $cached = $this->readCache($server);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $fresh = $this->runFreshProbe($server);
        } catch (\Throwable $e) {
            $fresh = new EngineLiveState(
                engine: $this->engineKey(),
                capturedAt: CarbonImmutable::now(),
                isFresh: true,
                units: [],
                engineSpecific: ['errors' => [$e->getMessage()]],
            );
        }

        $this->writeCache($server, $fresh);

        return $fresh;
    }

    protected function readCache(Server $server): ?EngineLiveState
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $bucket = $meta['webserver_live_state'] ?? null;
        if (! is_array($bucket)) {
            return null;
        }
        $payload = $bucket[$this->engineKey()] ?? null;
        $state = EngineLiveState::fromArray($payload);
        if ($state === null) {
            return null;
        }

        $ttl = max(1, (int) config('server_manage.webserver_live_state_cache_seconds', 60));
        if ($state->capturedAt->lt(CarbonImmutable::now()->subSeconds($ttl))) {
            return null;
        }

        // Force isFresh=false on cache reads regardless of how the payload
        // was originally written — the UI uses this to render the "X
        // minutes ago" stamp differently from a freshly-pulled snapshot.
        $cached = new EngineLiveState(
            engine: $state->engine,
            capturedAt: $state->capturedAt,
            isFresh: false,
            units: $state->units,
            engineSpecific: $state->engineSpecific,
        );

        // Persist is_fresh=false so blade reads (which load meta directly,
        // not through readCache) can show the Cached badge on revisit.
        if ($state->isFresh) {
            $this->writeCache($server, $cached);
        }

        return $cached;
    }

    protected function writeCache(Server $server, EngineLiveState $state): void
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $bucket = is_array($meta['webserver_live_state'] ?? null) ? $meta['webserver_live_state'] : [];
        $bucket[$this->engineKey()] = $state->toArray();
        $meta['webserver_live_state'] = $bucket;
        $server->forceFill(['meta' => $meta])->saveQuietly();
    }
}
