<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerRemoteAccessEvent;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\ServerSshSession;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * Single eager-loaded snapshot for the access graph workspace render.
 */
final class ServerSshAccessContext
{
    /**
     * @param  Collection<int, ServerAuthorizedKey>  $authorizedKeys
     * @param  Collection<int, ServerSshSession>  $sessions
     * @param  Collection<int, ServerSshKeyAuditEvent>  $auditEvents
     * @param  Collection<int, ServerRemoteAccessEvent>  $remoteAccessEvents
     */
    public function __construct(
        public readonly Collection $authorizedKeys,
        public readonly Collection $sessions,
        public readonly Collection $auditEvents,
        public readonly Collection $remoteAccessEvents,
    ) {}

    public static function load(Server $server): self
    {
        return new self(
            authorizedKeys: $server->authorizedKeys()
                ->with([
                    'managedKey' => function (MorphTo $morphTo): void {
                        $morphTo->morphWith([
                            ServerSshSession::class => ['createdBy'],
                        ]);
                    },
                ])
                ->get(),
            sessions: ServerSshSession::query()
                ->where('server_id', $server->id)
                ->with('createdBy')
                ->orderBy('provisioned_at')
                ->get(),
            auditEvents: ServerSshKeyAuditEvent::query()
                ->where('server_id', $server->id)
                ->with('user')
                ->orderBy('created_at')
                ->get(),
            remoteAccessEvents: ServerRemoteAccessEvent::query()
                ->where('server_id', $server->id)
                ->with('user')
                ->orderByDesc('started_at')
                ->limit((int) config('server_ssh_access.remote_access_timeline_limit', 200))
                ->get(),
        );
    }

    /**
     * @return Collection<int, ServerSshSession>
     */
    public function activeSessions(): Collection
    {
        return $this->sessions
            ->filter(fn (ServerSshSession $session): bool => $session->revoked_at === null && $session->expires_at->isFuture())
            ->sortBy('expires_at')
            ->values();
    }
}
