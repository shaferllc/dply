<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshSession;
use App\Models\User;
use App\Models\UserSshKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Time-boxed contractor SSH sessions — provision authorized_keys rows with auto-revoke.
 */
final class ServerSshSessionManager
{
    public function __construct(
        private ServerAuthorizedKeysSynchronizer $synchronizer,
    ) {}

    public function grant(
        Server $server,
        User $actor,
        string $name,
        string $publicKey,
        Carbon $expiresAt,
        string $targetLinuxUser = '',
    ): ServerSshSession {
        $organization = $server->organization;
        if (! $organization instanceof Organization) {
            throw new RuntimeException('Server has no organization for SSH session provisioning.');
        }

        $line = trim($publicKey);
        if (! UserSshKey::publicKeyLooksValid($line)) {
            throw new RuntimeException('Invalid SSH public key.');
        }

        $fingerprint = SshPublicKeyFingerprint::forLine($line);
        if ($fingerprint === null) {
            throw new RuntimeException('Could not fingerprint SSH public key.');
        }

        $maxHours = max(1, (int) config('server_ssh_sessions.max_duration_hours', 168));
        if ($expiresAt->gt(now()->addHours($maxHours))) {
            throw new RuntimeException(sprintf('Session expiry cannot exceed %d hours.', $maxHours));
        }

        if ($expiresAt->lte(now())) {
            throw new RuntimeException('Session expiry must be in the future.');
        }

        $session = ServerSshSession::query()->create([
            'organization_id' => $organization->id,
            'server_id' => $server->id,
            'created_by_user_id' => $actor->id,
            'name' => trim($name),
            'public_key_fingerprint' => $fingerprint['sha256'],
            'target_linux_user' => trim($targetLinuxUser),
            'expires_at' => $expiresAt,
            'provisioned_at' => now(),
        ]);

        $authorizedKey = ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'target_linux_user' => trim($targetLinuxUser),
            'managed_key_type' => ServerSshSession::class,
            'managed_key_id' => $session->id,
            'name' => $this->keyName($session),
            'public_key' => $line,
            'review_after' => $expiresAt->toDateString(),
        ]);

        $session->update(['server_authorized_key_id' => $authorizedKey->id]);

        if ($server->isReady()) {
            try {
                $this->synchronizer->sync($server);
            } catch (\Throwable $e) {
                Log::warning('Failed to sync authorized keys after SSH session grant', [
                    'session_id' => $session->id,
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        audit_log($organization, $actor, 'server.ssh_session.granted', $session, null, [
            'server_id' => (string) $server->id,
            'server' => $server->name,
            'expires_at' => $expiresAt->toIso8601String(),
            'fingerprint' => $fingerprint['sha256'],
        ]);

        return $session->fresh(['serverAuthorizedKey', 'createdBy']);
    }

    public function revoke(ServerSshSession $session): void
    {
        if ($session->isRevoked()) {
            return;
        }

        $session->loadMissing(['server.organization', 'createdBy']);

        if ($session->server_authorized_key_id !== null) {
            ServerAuthorizedKey::query()
                ->whereKey($session->server_authorized_key_id)
                ->delete();
        }

        $session->update(['revoked_at' => now()]);

        $server = $session->server;
        if ($server instanceof Server && $server->isReady()) {
            try {
                $this->synchronizer->sync($server);
            } catch (\Throwable $e) {
                Log::warning('Failed to sync authorized keys after SSH session revoke', [
                    'session_id' => $session->id,
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        audit_log($session->organization, $session->createdBy, 'server.ssh_session.revoked', $session, null, [
            'server_id' => (string) $session->server_id,
            'reason' => $session->isExpired() ? 'expired' : 'manual',
        ]);
    }

    public function revokeExpired(): int
    {
        $count = 0;

        ServerSshSession::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->each(function (ServerSshSession $session) use (&$count): void {
                $this->revoke($session);
                $count++;
            });

        return $count;
    }

    private function keyName(ServerSshSession $session): string
    {
        return sprintf('session:%s (until %s)', $session->name, $session->expires_at->format('Y-m-d H:i'));
    }
}
