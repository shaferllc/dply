<?php

declare(strict_types=1);

namespace App\Services\Sites\Promote;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Support\Preview\UnifiedPreviewHostname;
use Illuminate\Support\Str;

/**
 * Generates a unique managed-preview hostname for a promote-to-server clone.
 */
final class SitePromoteHostnameResolver
{
    public function __construct(
        private readonly UnifiedPreviewHostname $previewHostnames,
    ) {}

    public function resolve(Site $source, Server $destination): string
    {
        $apex = $this->previewHostnames->preferredApex();
        $baseLabel = $this->previewHostnames->siteLabel($source);
        $suffix = Str::lower(substr(sha1($source->id.'|'.$destination->id), 0, 6));

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $label = Str::limit($baseLabel.'-standby'.($attempt > 0 ? '-'.$attempt : ''), 63, '');
            $hostname = strtolower($label.'.'.$apex);

            if (! $this->hostnameTaken($hostname)) {
                return $hostname;
            }
        }

        $hostname = strtolower(Str::limit($baseLabel.'-standby-'.$suffix, 63, '').'.'.$apex);

        return $this->hostnameTaken($hostname)
            ? strtolower(Str::random(12).'.'.$apex)
            : $hostname;
    }

    private function hostnameTaken(string $hostname): bool
    {
        $host = strtolower(trim($hostname));

        return SiteDomain::query()->where('hostname', $host)->exists()
            || SitePreviewDomain::query()->where('hostname', $host)->exists();
    }
}
