<?php

declare(strict_types=1);

namespace App\Services\DeployIntelligence\Rules;

use App\Models\DeployIntelligenceAlert;
use App\Models\Organization;
use App\Services\DeployIntelligence\AlertFinding;
use App\Services\DeployIntelligence\Contracts\IntelligenceRule;

/**
 * Flagged Edge sites whose preview and production scopes had diverged in key
 * set — comparing keys only, since values legitimately differ but a missing
 * key is a deploy-time surprise waiting to happen.
 *
 * Edge is removed (remove-cloud-edge-serverless) and this rule only ever read
 * EdgeSiteEnvVar, so it can never produce a finding again. Neutered rather
 * than deleted so removing it from Scanner and dropping the file stays your
 * call; nothing else references the class.
 */
class EnvDriftRule implements IntelligenceRule
{
    public function key(): string
    {
        return DeployIntelligenceAlert::RULE_ENV_DRIFT;
    }

    /**
     * @return list<AlertFinding>
     */
    public function evaluate(Organization $organization): array
    {
        return [];
    }
}
