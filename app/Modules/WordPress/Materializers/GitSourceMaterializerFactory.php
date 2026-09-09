<?php

declare(strict_types=1);

namespace App\Modules\WordPress\Materializers;

use App\Models\Site;
use App\Modules\WordPress\Contracts\GitSourceMaterializer;

/**
 * Picks the materializer for a site's WordPress layout.
 *
 * The layout lives at meta.scaffold.layout, written by ChooseApp from the
 * tile's `wp_layout`. Both WordPress tiles are framework=wordpress, so the
 * framework cannot be the discriminator — the layout is.
 *
 * Classic is the default for anything unlabelled: sites scaffolded before the
 * layout key existed are all classic `wp core download` installs, and treating
 * one of them as Bedrock would run composer against a tree that has no
 * composer.json.
 */
class GitSourceMaterializerFactory
{
    public function __construct(
        private readonly ClassicGitSourceMaterializer $classic,
        private readonly BedrockGitSourceMaterializer $bedrock,
    ) {}

    public function for(Site $site): GitSourceMaterializer
    {
        return $this->layoutOf($site) === 'bedrock' ? $this->bedrock : $this->classic;
    }

    public function layoutOf(Site $site): string
    {
        $layout = strtolower(trim((string) data_get($site->meta, 'scaffold.layout')));

        return $layout === 'bedrock' ? 'bedrock' : 'classic';
    }

    /**
     * Whether this site can host theme/plugin repos at all. Guards the UI and
     * the job: pointing a WordPress theme repo at a Laravel site would clone
     * into a wp-content directory that does not exist.
     */
    public function supports(Site $site): bool
    {
        return strtolower(trim((string) data_get($site->meta, 'scaffold.framework'))) === 'wordpress';
    }
}
