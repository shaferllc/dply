<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Support\Servers\DatabaseWorkspaceEngines;
use App\Support\Servers\ServerDatabaseHostCapabilities;

/**
 * Single gate every "create a database" path consults before inserting a
 * ServerDatabase row: the engine must be both INSTALLED and actually LISTENING on
 * TCP 127.0.0.1:<port> — the address the deployed app dials.
 *
 * Why both checks: the capability probe answers "is the engine here?" over the
 * unix socket / sudo, but the app connects over TCP loopback. An engine that's
 * installed-but-not-TCP-listening sails past the old socket-only check and then
 * can't serve the app ("I made a database but it can't reach itself"). This guard
 * closes that gap so the failure surfaces at create time with an actionable
 * message instead of as a silently-unreachable binding later.
 */
final class DatabaseEngineReadinessGuard
{
    public function __construct(
        private readonly ServerDatabaseHostCapabilities $capabilities,
        private readonly ServerDatabaseRemoteExec $remote,
    ) {}

    /**
     * @return array{ok: bool, reason: ?string}
     */
    /** @return array<string, mixed> */
    public function check(Server $server, string $engine): array
    {
        // sqlite is a file on disk — no engine daemon, no TCP surface.
        if ($engine === 'sqlite') {
            return ['ok' => true, 'reason' => null];
        }

        $label = DatabaseWorkspaceEngines::label($engine);

        if (! ($this->capabilities->forServer($server)[$engine] ?? false)) {
            return [
                'ok' => false,
                'reason' => __(':engine is not installed on this server — install it from the Databases tab first.', ['engine' => $label]),
            ];
        }

        if (! $this->remote->engineListeningOnLoopback($server, $engine)) {
            return [
                'ok' => false,
                'reason' => __(':engine is installed but is not accepting TCP connections on 127.0.0.1::port — it may be socket-only or stopped. Make it listen on localhost and restart it before creating a database (the app connects over TCP, so a database here would be unreachable).', [
                    'engine' => $label,
                    'port' => ServerDatabaseEngine::defaultPortFor($engine),
                ]),
            ];
        }

        return ['ok' => true, 'reason' => null];
    }

    /** Convenience: true when the engine is installed AND TCP-reachable. */
    public function isReady(Server $server, string $engine): bool
    {
        return $this->check($server, $engine)['ok'];
    }
}
