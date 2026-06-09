<?php

declare(strict_types=1);

namespace App\Services\Secrets\Sources;

use App\Services\Secrets\Contracts\SecretSource;
use App\Services\Secrets\Scope;
use RuntimeException;

/**
 * SEAM ONLY (v1). Future customer-facing env vault: a portable JSON bundle of an
 * organization's secrets (WorkspaceVariable values, site env, …) escrowed under
 * the dply-managed per-org scope. Deliberately not implemented yet — gathering
 * and re-exporting customer secrets is sensitive and gets its own review with
 * the customer requirements. The interface shape is fixed so the rest of the
 * vault already threads `Scope::org()`.
 */
final class OrgEnvBundleSource implements SecretSource
{
    public function name(): string
    {
        return 'org-env-bundle';
    }

    public function gather(Scope $scope): string
    {
        throw new RuntimeException('org-env-bundle is a v1 seam and is not implemented yet.');
    }
}
