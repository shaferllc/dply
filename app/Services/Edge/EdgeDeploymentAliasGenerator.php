<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Support\Edge\EdgeTestingDomains;
use Illuminate\Support\Str;

/**
 * Builds the stable per-deployment alias hostnames that get published
 * into the KV host map alongside the site's primary hostname.
 *
 * Aliases live on the same on-dply apex the site already publishes from
 * so the wildcard worker route picks them up without extra Cloudflare
 * config. Two flavors per deploy:
 *
 *   - commit alias: {slug}--{sha7}.{apex} (only when git_commit is set)
 *   - deploy alias: {slug}--d-{ulid-tail8}.{apex}
 *
 * Using `--` (double-dash) as the separator keeps the alias visually
 * distinct from the primary hostname (which uses single dash + a 6-char
 * suffix) and avoids ambiguity with site slugs that contain dashes.
 *
 * Preview sites already have unique per-commit URLs so they only get
 * the deploy-id alias — a commit alias would duplicate the existing
 * preview hostname.
 */
class EdgeDeploymentAliasGenerator
{
    /**
     * @return list<string>
     */
    public function aliasesFor(Site $site, EdgeDeployment $deployment): array
    {
        if (! $site->usesEdgeRuntime()) {
            return [];
        }

        $apex = $this->apexFor($site);
        if ($apex === '') {
            return [];
        }

        $slug = $this->aliasSlug($site);
        if ($slug === '') {
            return [];
        }

        $aliases = [];
        $deployTail = strtolower(substr((string) $deployment->id, -8));
        if ($deployTail !== '') {
            $aliases[] = strtolower($slug.'--d-'.$deployTail.'.'.$apex);
        }

        $commit = is_string($deployment->git_commit) ? strtolower(trim($deployment->git_commit)) : '';
        if (! $site->isEdgePreview() && preg_match('/^[a-f0-9]{7,40}$/', $commit) === 1) {
            $aliases[] = strtolower($slug.'--'.substr($commit, 0, 7).'.'.$apex);
        }

        return array_values(array_unique($aliases));
    }

    private function apexFor(Site $site): string
    {
        $hostname = strtolower(trim($site->edgeHostname()));
        if ($hostname !== '' && str_contains($hostname, '.')) {
            $apex = substr($hostname, strpos($hostname, '.') + 1);
            if ($apex !== '' && str_contains($apex, '.')) {
                return $apex;
            }
        }

        return EdgeTestingDomains::defaultApex();
    }

    private function aliasSlug(Site $site): string
    {
        $slug = (string) ($site->slug ?? '');
        if ($slug === '') {
            $slug = Str::slug((string) $site->name);
        }

        return strtolower(preg_replace('/-{2,}/', '-', $slug) ?? '');
    }
}
