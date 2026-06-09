<?php

declare(strict_types=1);

namespace App\Services\Secrets\Sources;

use App\Services\Secrets\Contracts\SecretSource;
use App\Services\Secrets\Scope;
use RuntimeException;

/**
 * SEAM ONLY (v1). Future per-site secret bundle for the customer-facing vault.
 * Not implemented yet — see {@see OrgEnvBundleSource} for the rationale.
 */
final class SiteEnvBundleSource implements SecretSource
{
    public function name(): string
    {
        return 'site-env-bundle';
    }

    public function gather(Scope $scope): string
    {
        throw new RuntimeException('site-env-bundle is a v1 seam and is not implemented yet.');
    }
}
