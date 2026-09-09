<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Scripts;

use App\Models\Organization;
use App\Models\Script;
use App\Models\User;

/**
 * Clone a marketplace script preset (config/marketplace/scripts.php) into an
 * organization's scripts. Preset bodies stay in config — the marketplace
 * catalog only carries a row pointing at the key — so this is the one place
 * that reads a preset and turns it into a Script.
 */
final class CloneScriptPreset
{
    public function clone(string $key, Organization $organization, User $actor): ?Script
    {
        $preset = config('script_marketplace.'.$key);
        if (! is_array($preset) || empty($preset['name']) || ! isset($preset['content'])) {
            return null;
        }

        return Script::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'name' => $preset['name'],
            'content' => (string) $preset['content'],
            'run_as_user' => isset($preset['run_as_user']) && $preset['run_as_user'] !== ''
                ? (string) $preset['run_as_user']
                : null,
            'source' => Script::SOURCE_MARKETPLACE,
            'marketplace_key' => $key,
        ]);
    }
}
