<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Models\Server;

/**
 * Contract for per-engine live-state probes. Each engine (nginx, caddy,
 * apache, openlitespeed, traefik, haproxy) ships one implementation.
 *
 * Implementations are responsible for:
 *   - Knowing which SSH commands / file reads / API calls extract live
 *     state from the engine on the box.
 *   - Parsing those outputs into the structured units arrays.
 *   - Updating `Server.meta.webserver_live_state[engine]` when run with
 *     forceFresh=true (so the cached copy reflects the latest probe).
 *
 * Implementations should never throw on probe failure — the result must
 * always be an EngineLiveState (possibly with empty units), so the UI
 * has SOMETHING to render. Probe failures land as warning entries in
 * EngineLiveState::engineSpecific['errors'].
 */
interface EngineLiveStateProbe
{
    /**
     * One of the dply engine keys: 'nginx', 'caddy', 'apache',
     * 'openlitespeed', 'traefik', 'haproxy'.
     */
    public function engineKey(): string;

    /**
     * Pull the engine's current live state.
     *
     *   - forceFresh=true: ALWAYS run a fresh probe (SSH/API/file read).
     *     Update the cached copy on `Server.meta.webserver_live_state[]`
     *     so future cached reads see the new data.
     *   - forceFresh=false: return the cached copy from Server.meta if
     *     present; otherwise run a fresh probe and cache it.
     *
     * Either way, the returned EngineLiveState's `isFresh` flag tells
     * the caller which path it came from.
     */
    public function probe(Server $server, bool $forceFresh = false): EngineLiveState;
}
