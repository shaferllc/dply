<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\Site;
use App\Support\Edge\FakeEdgeProvision;

class EdgeRouter
{
    /**
     * @return array<string, class-string<EdgeBackend>>
     */
    public static function backends(): array
    {
        return [
            'dply_edge' => DplyEdgeBackend::class,
            'org_cloudflare' => OrgCloudflareEdgeBackend::class,
        ];
    }

    public static function backendFor(Site $site): ?EdgeBackend
    {
        $key = $site->edge_backend;
        if (! is_string($key) || $key === '') {
            return null;
        }

        if (FakeEdgeProvision::enabled()) {
            return new FakeEdgeBackend;
        }

        $map = self::backends();
        if (! isset($map[$key])) {
            return null;
        }

        $class = $map[$key];

        return app()->make($class);
    }
}
