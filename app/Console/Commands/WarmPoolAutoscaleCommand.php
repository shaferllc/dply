<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\CreateWarmPoolMember;
use App\Actions\Servers\DeleteServerAction;
use App\Jobs\PersonalizeClaimedServerJob;
use App\Models\Server;
use App\Models\ServerPoolMember;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Keep each configured warm-pool bucket topped up to its `min` and trim idle
 * members above its `max`. Runs every minute (gated by warm_pool.enabled).
 *
 * Reconcile first (cheap, idempotent): warming members whose server finished
 * provisioning flip to `ready`; members whose server errored/vanished flip to
 * `failed` so they're replaced and don't get claimed. Then per bucket, create
 * up to the deficit (capped per tick to avoid bursts) and retire surplus idle
 * members. Mirrors the WorkerPoolAutoscale pattern.
 */
class WarmPoolAutoscaleCommand extends Command
{
    protected $signature = 'dply:warm-pool:autoscale {--dry-run : Report actions without creating/retiring}';

    protected $description = 'Top up warm server pool buckets to their min and retire idle surplus.';

    /** Cap new members created per bucket per tick so a cold start ramps gently. */
    private const CREATE_CAP_PER_TICK = 3;

    public function handle(CreateWarmPoolMember $creator): int
    {
        if (! (bool) config('warm_pool.enabled', false)) {
            $this->info('Warm pool disabled (warm_pool.enabled=false).');

            return self::SUCCESS;
        }

        $buckets = (array) config('warm_pool.buckets', []);
        if ($buckets === []) {
            $this->info('No warm-pool buckets configured.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');

        $this->reconcile($dry);
        $this->reconcileClaimed($dry);

        foreach ($buckets as $bucket) {
            if (! is_array($bucket)) {
                continue;
            }
            $this->reconcileBucket($creator, $bucket, $dry);
        }

        return self::SUCCESS;
    }

    /**
     * Flip warming→ready (provision done) and →failed (server gone/errored).
     */
    private function reconcile(bool $dry): void
    {
        $warming = ServerPoolMember::query()
            ->where('status', ServerPoolMember::STATUS_WARMING)
            ->get();

        foreach ($warming as $member) {
            $server = $member->server_id ? Server::query()->find($member->server_id) : null;

            if (! $server) {
                $this->transition($member, ServerPoolMember::STATUS_FAILED, $dry, 'server row missing');

                continue;
            }

            if ($server->status === Server::STATUS_ERROR || $server->setup_status === Server::SETUP_STATUS_FAILED) {
                $this->transition($member, ServerPoolMember::STATUS_FAILED, $dry, 'provision failed');

                continue;
            }

            if ($server->status === Server::STATUS_READY && $server->setup_status === Server::SETUP_STATUS_DONE) {
                if (! $dry) {
                    $member->update([
                        'status' => ServerPoolMember::STATUS_READY,
                        'health_checked_at' => now(),
                    ]);
                }
                $this->line("  [ready] member {$member->id} (server {$server->id})");

                continue;
            }

            // Stranded: a member whose provision silently wedged (never ERROR,
            // never DONE — e.g. a baseline box that produced no setup, or a lost
            // task callback) would otherwise sit in 'warming' forever, counting
            // as available() and suppressing refill. Time-bound it to 'failed' so
            // the bucket refills.
            $maxWarming = (int) config('warm_pool.max_warming_seconds', 1800);
            if ($maxWarming > 0 && $member->created_at->lt(now()->subSeconds($maxWarming))) {
                $this->transition($member, ServerPoolMember::STATUS_FAILED, $dry, "warming > {$maxWarming}s — provision wedged");
            }
        }
    }

    /**
     * Backstop for a claimed member whose PersonalizeClaimedServerJob never ran
     * (e.g. lost on a worker/Redis restart between the adopt commit and enqueue).
     * Without this the customer's server is stuck READY+PENDING with the POOL
     * org's SSH keys still in authorized_keys and no re-trigger. Re-dispatch
     * personalize (idempotent — the resume-safe provision pipeline) for claimed
     * members whose server hasn't finished setup after a grace window.
     */
    private function reconcileClaimed(bool $dry): void
    {
        $grace = (int) config('warm_pool.personalize_backstop_seconds', 300);
        if ($grace <= 0) {
            return;
        }

        $stuck = ServerPoolMember::query()
            ->where('status', ServerPoolMember::STATUS_CLAIMED)
            ->where('claimed_at', '<', now()->subSeconds($grace))
            ->get();

        foreach ($stuck as $member) {
            $server = $member->server_id ? Server::query()->find($member->server_id) : null;
            if (! $server || $server->setup_status === Server::SETUP_STATUS_DONE) {
                continue;
            }

            $this->line("  [re-personalize] member {$member->id} (server {$server->id}) — setup not done after {$grace}s");
            if (! $dry) {
                PersonalizeClaimedServerJob::dispatch($server->id, $member->id, $member->tier);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $bucket
     */
    private function reconcileBucket(CreateWarmPoolMember $creator, array $bucket, bool $dry): void
    {
        $provider = (string) ($bucket['provider'] ?? '');
        $region = (string) ($bucket['region'] ?? '');
        $size = (string) ($bucket['size'] ?? '');
        $tier = (string) ($bucket['tier'] ?? ServerPoolMember::TIER_BASELINE);
        $min = max(0, (int) ($bucket['min'] ?? 0));
        $max = max($min, (int) ($bucket['max'] ?? $min));

        if ($provider === '' || $region === '' || $size === '') {
            return;
        }

        // Off-hours: collapse the bucket toward the off-hours min (default 0) so
        // idle spares retire overnight and aren't refilled.
        if ($this->inOffHours()) {
            $offMin = (int) ($bucket['off_hours_min'] ?? config('warm_pool.off_hours.min', 0));
            $min = max(0, min($min, $offMin));
            $max = $min;
        }

        $base = ServerPoolMember::query()->forBucket($provider, $region, $size, $tier);

        // Retire stale ready members (security drift) so the refill below
        // replaces them with fresh, patched ones — gentle, capped per tick.
        $this->retireStale($base, $dry);

        $available = (clone $base)->available()->count();
        $ready = (clone $base)->where('status', ServerPoolMember::STATUS_READY)->count();
        $label = "{$provider}/{$region}/{$size}/{$tier}";

        // Top up to min (capped per tick).
        if ($available < $min) {
            $deficit = min($min - $available, self::CREATE_CAP_PER_TICK);
            $this->info("  [create x{$deficit}] {$label} (available {$available} < min {$min})");
            for ($i = 0; $i < $deficit; $i++) {
                if ($dry) {
                    continue;
                }
                try {
                    $creator->create($bucket);
                } catch (\Throwable $e) {
                    Log::error('warm_pool.autoscale.create_failed', ['bucket' => $label, 'message' => $e->getMessage()]);
                }
            }
        }

        // Retire idle surplus above max (only ready ones — never kill warming).
        if ($ready > $max) {
            $surplus = $ready - $max;
            $this->info("  [retire x{$surplus}] {$label} (ready {$ready} > max {$max})");
            $extras = (clone $base)
                ->where('status', ServerPoolMember::STATUS_READY)
                ->orderBy('created_at')
                ->limit($surplus)
                ->get();
            foreach ($extras as $member) {
                $this->retireMember($member, $dry, 'surplus over max');
            }
        }
    }

    /**
     * Retire ready members older than max_member_age_seconds so the next refill
     * replaces them with fresh (security-patched) ones. Capped per tick.
     *
     * @param  Builder<ServerPoolMember>  $base
     */
    private function retireStale(Builder $base, bool $dry): void
    {
        $maxAge = (int) config('warm_pool.max_member_age_seconds', 0);
        if ($maxAge <= 0) {
            return;
        }

        $cap = max(1, (int) config('warm_pool.retire_cap_per_tick', 1));
        $stale = (clone $base)
            ->where('status', ServerPoolMember::STATUS_READY)
            ->where('created_at', '<', now()->subSeconds($maxAge))
            ->orderBy('created_at')
            ->limit($cap)
            ->get();

        foreach ($stale as $member) {
            /** @var ServerPoolMember $member */
            $this->retireMember($member, $dry, "stale (>{$maxAge}s) — replacing with fresh");
        }
    }

    private function retireMember(ServerPoolMember $member, bool $dry, string $reason): void
    {
        $this->transition($member, ServerPoolMember::STATUS_RETIRING, $dry, $reason);
        if ($dry) {
            return;
        }
        $server = $member->server_id ? Server::query()->find($member->server_id) : null;
        if ($server) {
            // Reuse the real removal action so the provider resource is actually
            // destroyed (not just the DB row).
            try {
                app(DeleteServerAction::class)
                    ->execute($server, null, ['reason' => 'warm_pool_retire']);
            } catch (\Throwable $e) {
                Log::error('warm_pool.retire.failed', ['member' => $member->id, 'message' => $e->getMessage()]);

                return;
            }
        }
        $member->delete();
    }

    /** True when the current app-tz hour is inside the configured off-hours window. */
    private function inOffHours(): bool
    {
        if (! (bool) config('warm_pool.off_hours.enabled', false)) {
            return false;
        }

        $hour = (int) now()->hour;
        $start = (int) config('warm_pool.off_hours.start', 22);
        $end = (int) config('warm_pool.off_hours.end', 6);

        // Wrapping window (e.g. 22→6) when start > end.
        return $start <= $end
            ? ($hour >= $start && $hour < $end)
            : ($hour >= $start || $hour < $end);
    }

    private function transition(ServerPoolMember $member, string $status, bool $dry, string $reason): void
    {
        $this->line("  [{$status}] member {$member->id} — {$reason}");
        if (! $dry) {
            $member->update(['status' => $status]);
        }
    }
}
