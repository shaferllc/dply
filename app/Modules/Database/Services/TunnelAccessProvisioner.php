<?php

declare(strict_types=1);

namespace App\Modules\Database\Services;

use App\Jobs\SyncAuthorizedKeysJob;
use App\Models\Server;
use App\Models\ServerSshSession;
use App\Models\User;
use App\Services\Servers\ServerSshSessionManager;
use App\Support\OpenSshEd25519KeyPairGenerator;
use App\Support\Servers\DatabaseConnectionTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mints a throwaway SSH key whose only power is forwarding to one database.
 *
 * Operators kept hitting two walls with their own keys: dply knows which public
 * key is authorized on the box but not where the private half lives, and a
 * developer carrying several keys trips the server's MaxAuthTries before the
 * right one is offered ("Too many authentication failures").
 *
 * A purpose-minted key removes both. The authorized_keys entry is restricted
 * with permitopen so the key can open exactly one forward and can never yield a
 * shell — it is strictly less powerful than the operator's own key, not more.
 */
class TunnelAccessProvisioner
{
    public function __construct(
        private readonly ServerSshSessionManager $sessions,
    ) {}

    public function ttlHours(): int
    {
        return max(1, (int) config('server_database.tunnel_access_ttl_hours', 12));
    }

    /**
     * Mint (or reuse) tunnel access for this operator on this server.
     */
    public function provision(
        Server $server,
        User $actor,
        DatabaseConnectionTarget $target,
    ): ServerSshSession {
        if (! $server->hostCapabilities()->supportsSsh()) {
            throw new RuntimeException('This server does not accept SSH connections.');
        }

        if ($target->host === '') {
            throw new RuntimeException('The database has no host to forward to yet.');
        }

        // Re-running the installer rotates rather than accumulating: at most one
        // live tunnel key per operator per server, and the previous one stops
        // working the moment a replacement is issued.
        foreach ($this->liveSessions($server, $actor) as $previous) {
            $this->sessions->revoke($previous, syncNow: false);
        }

        [$privateKey, $publicKey] = OpenSshEd25519KeyPairGenerator::generate();

        $session = $this->sessions->grant(
            server: $server,
            actor: $actor,
            name: $this->sessionName($actor),
            publicKey: $publicKey,
            expiresAt: now()->addHours($this->ttlHours()),
            targetLinuxUser: trim((string) $server->ssh_user) !== '' ? trim((string) $server->ssh_user) : 'root',
            keyOptions: self::restrictionsFor($target),
            privateKey: $privateKey,
            syncNow: false,
        );

        // The key reaches the box on the queue. Doing it inline would put an SSH
        // round trip in the HTTP request, which blocks the modal until PHP's
        // max_execution_time — the "Generating…" spinner that never resolves.
        SyncAuthorizedKeysJob::dispatch(
            (string) $server->id,
            (string) Str::ulid(),
            (string) $actor->id,
            request()->ip(),
        );

        return $session;
    }

    /**
     * The authorized_keys options that make this key a forwarder and nothing
     * else. permitopen pins the single destination; the no-* flags remove every
     * other capability an SSH session would otherwise carry.
     */
    public static function restrictionsFor(DatabaseConnectionTarget $target): string
    {
        return sprintf(
            'restrict,permitopen="%s:%d",port-forwarding',
            $target->host,
            $target->port,
        );
    }

    /** An installed, still-valid tunnel key for this operator, if any. */
    public function activeFor(Server $server, User $actor): ?ServerSshSession
    {
        return $this->liveSessionQuery($server, $actor)->first();
    }

    /** @return Collection<int, ServerSshSession> */
    private function liveSessions(Server $server, User $actor): Collection
    {
        return $this->liveSessionQuery($server, $actor)->get();
    }

    /** @return Builder<ServerSshSession> */
    private function liveSessionQuery(Server $server, User $actor): Builder
    {
        return ServerSshSession::query()
            ->where('server_id', $server->id)
            ->where('created_by_user_id', $actor->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('created_at');
    }

    /** Stable alias used for the generated SSH config host and key filename. */
    public static function aliasFor(ServerSshSession $session): string
    {
        return 'dply-db-'.mb_strtolower(mb_substr((string) $session->id, -8));
    }

    private function sessionName(User $actor): string
    {
        return 'Database tunnel · '.($actor->name ?: $actor->email);
    }
}
