<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Organization;
use App\Models\ServerProvisionStepRun;
use Illuminate\Support\Facades\Cache;

/**
 * Computes the average duration for a provision step from the
 * org-scoped history in `server_provision_step_runs`. Drives the
 * data-driven ETA on the journey UI ("Avg 1m 25s from 12 previous
 * runs") in place of the static "Usually X minutes" copy.
 *
 * Resumed-skip rows are excluded from the average — a 0-second skip
 * would drag the running mean toward zero on every Resume install.
 */
class ProvisionStepEtaService
{
    /**
     * @return array{seconds: int, samples: int}|null
     *                                                null when fewer than `step_eta_min_samples` non-resumed rows
     *                                                exist for this label_hash on this org.
     */
    public function averageForLabel(?string $labelHash, ?Organization $organization): ?array
    {
        if ($labelHash === null || $labelHash === '' || $organization === null) {
            return null;
        }

        $minSamples = max(1, (int) config('server_provision.step_eta_min_samples', 3));
        $ttl = max(0, (int) config('server_provision.step_eta_cache_ttl_seconds', 600));

        $cacheKey = "provision-step-eta:{$organization->id}:{$labelHash}";

        $payload = Cache::remember($cacheKey, $ttl, function () use ($labelHash, $organization): array {
            $row = ServerProvisionStepRun::query()
                ->where('organization_id', $organization->id)
                ->where('label_hash', $labelHash)
                ->where('resumed', false)
                ->whereNotNull('completed_at')
                ->where('duration_seconds', '>', 0)
                ->selectRaw('AVG(duration_seconds) as avg_seconds, COUNT(*) as sample_count')
                ->first();

            return [
                'seconds' => (int) round((float) ($row?->avg_seconds ?? 0)),
                'samples' => (int) ($row?->sample_count ?? 0),
            ];
        });

        if ($payload['samples'] < $minSamples) {
            return null;
        }

        return [
            'seconds' => max(0, $payload['seconds']),
            'samples' => $payload['samples'],
        ];
    }

    /**
     * Bulk-resolve averages for multiple label hashes in one query —
     * the journey UI calls this once for the whole "Up next" list so
     * each pending row gets an ETA chip without N+1 trips.
     *
     * @param  list<string>  $labelHashes
     * @return array<string, array{seconds: int, samples: int}> keyed by label_hash; missing keys = below threshold
     */
    public function averagesForLabels(array $labelHashes, ?Organization $organization): array
    {
        $hashes = array_values(array_unique(array_filter(
            $labelHashes,
            static fn (mixed $h): bool => is_string($h) && $h !== '',
        )));

        if ($hashes === [] || $organization === null) {
            return [];
        }

        $minSamples = max(1, (int) config('server_provision.step_eta_min_samples', 3));

        $rows = ServerProvisionStepRun::query()
            ->where('organization_id', $organization->id)
            ->whereIn('label_hash', $hashes)
            ->where('resumed', false)
            ->whereNotNull('completed_at')
            ->where('duration_seconds', '>', 0)
            ->selectRaw('label_hash, AVG(duration_seconds) as avg_seconds, COUNT(*) as sample_count')
            ->groupBy('label_hash')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $samples = (int) ($row->sample_count ?? 0);
            if ($samples < $minSamples) {
                continue;
            }
            $out[(string) $row->label_hash] = [
                'seconds' => max(0, (int) round((float) ($row->avg_seconds ?? 0))),
                'samples' => $samples,
            ];
        }

        return $out;
    }
}
