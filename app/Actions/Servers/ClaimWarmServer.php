<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Jobs\PersonalizeClaimedServerJob;
use App\Models\Server;
use App\Models\ServerPoolMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Satisfy a freshly-created MANAGED server from the warm pool instead of
 * cold-provisioning. Returns true when a warm member was claimed + adopted (the
 * caller must then NOT dispatch a cold provision job); false to fall back.
 *
 * Managed-only by design: a pooled VM lives on dply's platform account, so it
 * can only be handed to a managed-server create (same account). BYO creates
 * always cold-provision on the customer's own credential.
 *
 * Claiming is race-safe (DB txn + lockForUpdate, ready→claiming) so two
 * concurrent creates can never grab the same member.
 */
class ClaimWarmServer
{
    public function attempt(Server $server): bool
    {
        if (! (bool) config('warm_pool.enabled', false)) {
            return false;
        }

        // Pool VMs are on the platform account → only managed VMs can adopt one.
        if (! $server->usesManagedHosting() || ! $server->isVmHost()) {
            return false;
        }

        $provider = $server->provider?->value ?? '';
        $region = (string) $server->region;
        $size = (string) $server->size;
        if ($provider === '' || $region === '' || $size === '') {
            return false;
        }

        $stackSignature = ServerPoolMember::signatureFor($server->meta ?? []);
        $healthMaxAge = (int) config('warm_pool.health_max_age_seconds', 900);

        // Prefer a hot-stack member matching the requested stack (instant);
        // fall back to a baseline member (claim runs the stack install).
        $targets = [
            [ServerPoolMember::TIER_STACK, $stackSignature],
            [ServerPoolMember::TIER_BASELINE, null],
        ];

        foreach ($targets as [$tier, $signature]) {
            $member = $this->claimReadyMember($provider, $region, $size, $tier, $signature, $server, $healthMaxAge);
            if ($member === null) {
                continue;
            }

            if ($this->adopt($member, $server, $tier)) {
                PersonalizeClaimedServerJob::dispatch($server->id, $member->id, $tier);

                Log::info('warm_pool.claim.success', [
                    'member' => $member->id,
                    'server' => $server->id,
                    'tier' => $tier,
                ]);

                return true;
            }

            // Adoption failed after claiming — quarantine the member (don't let
            // it be re-claimed) and fall through to cold provisioning.
            $member->update(['status' => ServerPoolMember::STATUS_FAILED]);
            Log::warning('warm_pool.claim.adopt_failed', ['member' => $member->id, 'server' => $server->id]);
        }

        return false;
    }

    /**
     * Atomically claim one ready member for the bucket (ready→claiming) under a
     * row lock so concurrent creates can't double-claim. Skips members whose
     * health check is stale (the autoscaler re-checks/replaces them).
     */
    private function claimReadyMember(string $provider, string $region, string $size, string $tier, ?string $signature, Server $server, int $healthMaxAge): ?ServerPoolMember
    {
        return DB::transaction(function () use ($provider, $region, $size, $tier, $signature, $server, $healthMaxAge): ?ServerPoolMember {
            $member = ServerPoolMember::query()
                ->forBucket($provider, $region, $size, $tier)
                ->where('status', ServerPoolMember::STATUS_READY)
                ->when($tier === ServerPoolMember::TIER_STACK, fn ($q) => $q->where('stack_signature', $signature))
                ->when($healthMaxAge > 0, fn ($q) => $q->where('health_checked_at', '>=', now()->subSeconds($healthMaxAge)))
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $member) {
                return null;
            }

            $member->update([
                'status' => ServerPoolMember::STATUS_CLAIMING,
                'claimed_org_id' => $server->organization_id,
                'claimed_at' => now(),
            ]);

            return $member;
        });
    }

    /**
     * Move the warm VM into the customer's server row. Both are managed VMs on
     * the SAME platform account, so this is a metadata adoption — NOT a provider
     * transfer. We copy the VM identity into $server, then neutralize + delete
     * the pool's placeholder Server row WITHOUT destroying the live VM
     * (provider_id is nulled first so any later teardown can't reap it).
     */
    private function adopt(ServerPoolMember $member, Server $server, string $tier): bool
    {
        $pool = $member->server_id ? Server::query()->find($member->server_id) : null;
        if (! $pool || blank($pool->provider_id) || blank($pool->ip_address)) {
            return false;
        }

        return DB::transaction(function () use ($pool, $server, $member): bool {
            $meta = is_array($server->meta) ? $server->meta : [];
            $poolMeta = is_array($pool->meta) ? $pool->meta : [];
            foreach (['provision_step_snapshots', \App\Support\Servers\InstalledStack::META_KEY] as $carry) {
                if (isset($poolMeta[$carry])) {
                    $meta[$carry] = $poolMeta[$carry];
                }
            }

            $server->forceFill([
                'provider_id' => $pool->provider_id,
                'ip_address' => $pool->ip_address,
                'private_ip_address' => $pool->private_ip_address,
                'ssh_user' => $pool->ssh_user,
                'ssh_port' => $pool->ssh_port,
                'ssh_private_key' => $pool->ssh_private_key,
                'ssh_public_key' => $pool->ssh_public_key,
                'hetzner_network_id' => $pool->hetzner_network_id,
                // Personalization re-runs setup as the customer; hold READY until
                // it completes so the journey shows the (fast) finishing pass.
                'status' => Server::STATUS_READY,
                'setup_status' => Server::SETUP_STATUS_PENDING,
                'meta' => $meta,
            ])->save();

            // Detach the live VM from the pool row so deleting it can't reap the
            // droplet, then drop the now-redundant placeholder row.
            $pool->forceFill(['provider_id' => null])->save();
            $pool->delete();

            $member->update([
                'status' => ServerPoolMember::STATUS_CLAIMED,
                'server_id' => $server->id,
            ]);

            return true;
        });
    }
}
