<?php

namespace App\Services\Sites\Contracts;

use App\Models\Site;

interface SiteRuntimeProvisioner
{
    public function runtimeProfile(): string;

    public function provision(Site $site): void;

    /**
     * @return array{ok: bool, hostname: ?string, url: ?string, error: ?string, checked_at: string, checks: list<array<string, mixed>>}
     */
    public function readyResult(Site $site): array;
}
