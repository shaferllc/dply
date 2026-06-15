<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\SshConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Actively probe whether the deploy user's operational SSH key is still accepted,
 * with NO fallback to the root recovery key. The result is persisted to server
 * meta so the connection settings "Repair SSH access" card can show whether the
 * server is healthy on its operational key or has fallen back to relying on the
 * hidden root recovery key.
 *
 * SSH must never run inline in a Livewire request, so the UI dispatches this and
 * reflects the result on the next poll ({@see reloadOperationalSshStatus}).
 *
 * Persists to meta:
 *  - ssh_operational_status:    'healthy' | 'failing'
 *  - ssh_operational_tested_at: ISO-8601 timestamp of this probe
 *  - ssh_operational_error:     short failure reason (cleared on success)
 */
class ProbeServerOperationalSshJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(
        public string $serverId,
    ) {}

    public function handle(): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }

        $user = trim((string) $server->ssh_user) ?: 'root';
        $meta = $server->meta ?? [];
        $meta['ssh_operational_tested_at'] = now()->toIso8601String();

        // When the deploy user *is* root there is no separate operational key to
        // verify — operational and recovery are the same login.
        if ($user === 'root') {
            $meta['ssh_operational_status'] = 'healthy';
            unset($meta['ssh_operational_error']);
            $server->update(['meta' => $meta]);

            return;
        }

        $ok = false;
        $error = null;

        // Probe the operational role explicitly — NO fallback to the recovery
        // (root) key, so a failure here genuinely means the deploy user's key is
        // no longer accepted.
        $ssh = new SshConnection($server, $user, SshConnection::ROLE_OPERATIONAL);

        try {
            $ok = $ssh->connect(12);
            if ($ok) {
                // A trivial command confirms the session is actually usable, not
                // just that the auth handshake passed.
                $ssh->exec('true', 12);
            } else {
                $error = __('The operational key was rejected by :user@:host.', [
                    'user' => $user,
                    'host' => $server->ip_address,
                ]);
            }
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        } finally {
            $ssh->disconnect();
        }

        $meta['ssh_operational_status'] = $ok ? 'healthy' : 'failing';
        if ($ok) {
            unset($meta['ssh_operational_error']);
        } else {
            $meta['ssh_operational_error'] = mb_strimwidth((string) $error, 0, 300);
        }

        $server->update(['meta' => $meta]);
    }
}
