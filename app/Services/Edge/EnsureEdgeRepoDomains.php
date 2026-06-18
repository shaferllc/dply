<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * Ensures every hostname declared in dply.yaml `domains:` is attached
 * to the site. Idempotent: already-attached hostnames are skipped.
 *
 * Removing a hostname from the repo does NOT detach it — detaches are
 * explicit only via dashboard / API. This avoids data loss when
 * someone accidentally drops a domain from the file.
 */
class EnsureEdgeRepoDomains
{
    public function ensure(Site $site, EdgeDeployment $deployment): void
    {
        if (! $site->usesEdgeRuntime()) {
            return;
        }

        $repoConfig = ($deployment->repo_config );
        $declared = is_array($repoConfig['domains'] ?? null) ? $repoConfig['domains'] : [];
        if ($declared === []) {
            return;
        }

        $backend = EdgeRouter::backendFor($site);
        if ($backend === null) {
            return;
        }

        // Currently-attached hostnames live in edgeMeta.routing.custom_domains.
        $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
        $attached = is_array($routing['custom_domains'] ?? null) ? array_change_key_case($routing['custom_domains'], CASE_LOWER) : [];

        foreach ($declared as $hostname) {
            if (! is_string($hostname)) {
                continue;
            }
            $lower = strtolower(trim($hostname));
            if ($lower === '' || isset($attached[$lower])) {
                continue;
            }
            try {
                $backend->attachDomain($site->fresh(), $lower);
            } catch (\Throwable $e) {
                Log::warning('Edge auto-attach domain failed', [
                    'site_id' => $site->id,
                    'hostname' => $lower,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
