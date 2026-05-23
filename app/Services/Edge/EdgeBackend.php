<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Common interface for Edge delivery backends. The dply Edge layer talks
 * to backends through this and never imports Cloudflare SDK directly.
 */
interface EdgeBackend
{
    public function providerKey(): string;

    /**
     * Upload artifacts from $localArtifactDir and publish routing for $deployment.
     *
     * @return array{live_url: ?string, cf_kv_version: int}
     */
    public function publishDeployment(EdgeDeployment $deployment, Site $site, string $localArtifactDir): array;

    /**
     * Re-point routing at an existing deployment's already-stored artifacts (rollback).
     * Does not re-upload — artifacts must already be present in storage.
     *
     * @return array{live_url: ?string, cf_kv_version: int}
     */
    public function republishDeployment(EdgeDeployment $deployment, Site $site): array;

    public function unpublish(Site $site): void;

    /**
     * @return list<array{name: string, type: string, value: string, status: string}>
     */
    public function attachDomain(Site $site, string $hostname): array;

    public function detachDomain(Site $site, string $hostname): void;

    /**
     * @return array{phase: string, live_url: ?string, active_deployment_id: ?string}
     */
    public function inspect(Site $site): array;

    public function supportsSsr(): bool;
}
