<?php

namespace App\Services\Sites\WebserverConfig;

use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;

interface WebserverConfigEngineInterface
{
    public function webserver(): string;

    /**
     * Effective config text for preview / download (merged layers or full override).
     */
    public function effectiveConfig(Site $site, ?SiteWebserverConfigProfile $profile): string;

    /**
     * Hash of managed core without operator snippets (for “core changed” warnings).
     */
    public function managedCoreHash(Site $site): string;

    /**
     * @return array{ok: bool, message: string}
     */
    public function validateLocal(string $config): array;

    /**
     * Write pending config on the server, run engine test, then restore previous files.
     *
     * @return array{ok: bool, message: string}
     */
    public function validateRemote(Site $site, string $config, ?SiteWebserverConfigProfile $profile): array;
}
