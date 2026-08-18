<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use App\Support\Sites\LinkedOrganizationSecrets;

/**
 * Production env for Edge build + Worker bundles: linked org vault secrets
 * under per-site EdgeSiteEnvVar rows (site keys win). Reserved names stay
 * filtered by {@see EdgeSiteEnvVar::keyIsValid()}.
 */
final class EdgeProductionEnv
{
    public function __construct(
        private readonly LinkedOrganizationSecrets $linkedSecrets,
    ) {}

    /**
     * @return array<string, string>
     */
    public function forSite(Site $site): array
    {
        $env = [];
        foreach ($this->linkedSecrets->valuesForSite($site) as $key => $value) {
            if (! EdgeSiteEnvVar::keyIsValid($key)) {
                continue;
            }
            $env[$key] = $value;
        }

        foreach ($site->edgeEnvVars()->where('scope', 'production')->get() as $envVar) {
            if (! EdgeSiteEnvVar::keyIsValid($envVar->key)) {
                continue;
            }
            $env[$envVar->key] = (string) $envVar->value;
        }

        return $env;
    }
}
