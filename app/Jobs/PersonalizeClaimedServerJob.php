<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\SshConnectionFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Personalize a server that was just adopted from the warm pool: make it the
 * customer's, not the pool's.
 *
 * It reuses the normal provision pipeline (WaitForServerSshReadyJob →
 * RunSetupScriptJob) rather than hand-rolling SSH. Re-running the (idempotent,
 * resume-safe) provision script as the new owner:
 *   - rewrites the deploy user's authorized_keys to the CUSTOMER org's keys
 *     (install(1) replaces the file → the pool org's keys are dropped),
 *   - ensures the stack (skip-fast for a hot-stack member, full install for a
 *     baseline member),
 *   - writes the env, and on completion the TaskRunner observer flips
 *     setup_status=DONE → READY.
 *
 * NOTE (validation gate): host-identity rotation (sshd host keys + machine-id +
 * OS hostname) is NOT done here yet — for managed pool boxes never exposed to a
 * customer pre-claim the risk is low, but it should be added + validated on a
 * real managed box before relying on the pool in production.
 */
class PersonalizeClaimedServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $serverId,
        public string $memberId,
        public string $tier,
    ) {
        $this->onQueue(config('server_provision.queue', 'dply'));
    }

    public function handle(): void
    {
        $server = Server::find($this->serverId);
        if (! $server) {
            Log::warning('warm_pool.personalize.server_missing', ['server' => $this->serverId, 'member' => $this->memberId]);

            return;
        }

        Log::info('warm_pool.personalize.start', [
            'server' => $server->id,
            'member' => $this->memberId,
            'tier' => $this->tier,
        ]);

        // Best-effort: set the OS hostname to the customer's server name (the
        // pooled box booted as dply-warm-…). Non-fatal — if it fails the
        // provision re-run below still proceeds. (Host-key + machine-id rotation
        // is intentionally NOT done here yet — see class docblock.)
        $hostname = $this->sanitizeHostname($server->name);
        if ($hostname !== '' && filled($server->ip_address) && filled($server->ssh_private_key)) {
            try {
                app(SshConnectionFactory::class)->forServer($server)
                    ->exec('hostnamectl set-hostname '.escapeshellarg($hostname).' 2>/dev/null || true', 10);
            } catch (\Throwable $e) {
                Log::warning('warm_pool.personalize.hostname_failed', ['server' => $server->id, 'message' => $e->getMessage()]);
            }
        }

        // Re-run the provision pipeline as the new owner. The adopt step already
        // set status=READY + setup_status=PENDING; WaitForServerSshReadyJob
        // confirms reachability then dispatches RunSetupScriptJob.
        WaitForServerSshReadyJob::dispatch($server);
    }

    private function sanitizeHostname(?string $name): string
    {
        $slug = Str::of((string) $name)->lower()->replaceMatches('/[^a-z0-9-]+/', '-')->trim('-')->limit(63, '')->value();

        return $slug !== '' ? $slug : '';
    }
}
