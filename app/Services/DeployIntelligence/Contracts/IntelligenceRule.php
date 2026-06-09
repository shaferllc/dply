<?php

declare(strict_types=1);

namespace App\Services\DeployIntelligence\Contracts;

use App\Models\Organization;
use App\Services\DeployIntelligence\AlertFinding;

interface IntelligenceRule
{
    /**
     * Stable key used as `rule_key` on alert rows and on the dedupe
     * unique index. Lowercase snake_case.
     */
    public function key(): string;

    /**
     * Compute findings for this org. Return an empty list when the
     * condition is clean.
     *
     * @return list<AlertFinding>
     */
    public function evaluate(Organization $organization): array;
}
