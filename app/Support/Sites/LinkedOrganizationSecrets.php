<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\OrganizationSecret;
use App\Models\Site;

/**
 * Decrypts linked org vault secrets for deploy / Cloud spec writes.
 * Do not call from Livewire render paths.
 *
 * @see docs/ORG_SHARED_SECRETS.md
 */
final class LinkedOrganizationSecrets
{
    /**
     * @return array<string, string>
     */
    public function valuesForSite(Site $site): array
    {
        $secrets = OrganizationSecret::query()
            ->whereIn(
                'id',
                $site->organizationSecrets()->select('organization_secrets.id'),
            )
            ->get();

        $values = [];
        foreach ($secrets as $secret) {
            $values[$secret->key] = (string) $secret->value;
        }

        return $values;
    }

    /**
     * Merge linked secret values under a higher-priority map (later keys win).
     *
     * @param  array<string, string>  $over
     * @return array<string, string>
     */
    public function mergeUnder(array $over, Site $site): array
    {
        return array_merge($this->valuesForSite($site), $over);
    }
}
