<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Resolves effective tags / snippets / forms by merging dply.yaml
 * declarations with dashboard edgeMeta. Dashboard wins when the
 * operator has saved that section (key present on meta); otherwise
 * the repo snapshot is the baseline.
 */
final class EdgeEffectiveProductAddons
{
    /**
     * @return array{enabled?: bool, consent_required?: bool, tools?: list<array<string, mixed>>}
     */
    public static function tags(Site $site, ?EdgeDeployment $deployment): array
    {
        return self::section($site, $deployment, 'tags');
    }

    /**
     * @return array{enabled?: bool, items?: list<array<string, mixed>>}
     */
    public static function snippets(Site $site, ?EdgeDeployment $deployment): array
    {
        return self::section($site, $deployment, 'snippets');
    }

    /**
     * @return array{enabled?: bool, endpoints?: list<array<string, mixed>>}
     */
    public static function forms(Site $site, ?EdgeDeployment $deployment): array
    {
        return self::section($site, $deployment, 'forms');
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(Site $site, ?EdgeDeployment $deployment, string $key): array
    {
        $meta = $site->edgeMeta();
        if (array_key_exists($key, $meta) && is_array($meta[$key])) {
            return $meta[$key];
        }

        $repoConfig = is_array($deployment?->repo_config) ? $deployment->repo_config : [];
        $repo = $repoConfig[$key] ?? null;

        return is_array($repo) ? $repo : [];
    }
}
