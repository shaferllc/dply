<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Organization;
use RuntimeException;

/**
 * Thrown when creating a site would push an organization past the site
 * ceiling of its current plan. Acts as the last-line hard block at the
 * Site model boundary; user-facing flows pre-check {@see Organization::canCreateSite}
 * and surface a friendly upgrade prompt before this ever fires.
 */
class SiteQuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $organizationId,
        public readonly ?int $limit,
        public readonly string $planLabel,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            'Site limit reached for the %s plan (%s sites).',
            $planLabel,
            $limit === null ? 'unlimited' : (string) $limit,
        ));
    }

    public static function forOrganization(Organization $organization): self
    {
        $plan = $organization->currentSubscriptionPlan();

        return new self(
            organizationId: (string) $organization->getKey(),
            limit: $plan['max_sites'],
            planLabel: $plan['label'],
            message: $organization->siteLimitMessage(),
        );
    }
}
