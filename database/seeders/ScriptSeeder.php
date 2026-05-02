<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Script;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * Default organization-wide scripts for each workspace that has at least one member.
 */
class ScriptSeeder extends Seeder
{
    public const NAME_RELEASE_CONTEXT = 'Release context';

    public const NAME_COMPOSER_PRODUCTION = 'Composer install (production)';

    public const NAME_DISK_USAGE = 'Disk usage summary';

    public function run(): void
    {
        /** @var Collection<int, Organization> $orgs */
        $orgs = Organization::query()->orderBy('name')->get();

        if ($orgs->isEmpty()) {
            return;
        }

        $orgs->each(function (Organization $org): void {
            $user = $org->users()->wherePivot('role', 'owner')->first()
                ?? $org->users()->first();

            if (! $user) {
                return;
            }

            $this->seedScriptsForOrganization($user, $org);
        });
    }

    protected function seedScriptsForOrganization(User $user, Organization $org): void
    {
        $release = Script::query()->firstOrCreate(
            [
                'organization_id' => $org->id,
                'name' => self::NAME_RELEASE_CONTEXT,
            ],
            [
                'user_id' => $user->id,
                'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
# Example deploy hook: prints runtime context. Adjust paths for your app root on the server.
echo "Release context"
echo "Host: $(hostname -s 2>/dev/null || hostname)"
echo "Date: $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "User: $(whoami)"
echo "PWD (if set): ${PWD:-<unset>}"
SH,
                'run_as_user' => null,
                'source' => Script::SOURCE_USER_CREATED,
                'marketplace_key' => null,
            ]
        );

        Script::query()->firstOrCreate(
            [
                'organization_id' => $org->id,
                'name' => self::NAME_COMPOSER_PRODUCTION,
            ],
            [
                'user_id' => $user->id,
                'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
# Replace /var/www/current with your site root on the server (often the "current" release symlink).
cd /var/www/current
composer install --no-dev --no-scripts --no-interaction
SH,
                'run_as_user' => null,
                'source' => Script::SOURCE_USER_CREATED,
                'marketplace_key' => null,
            ]
        );

        Script::query()->firstOrCreate(
            [
                'organization_id' => $org->id,
                'name' => self::NAME_DISK_USAGE,
            ],
            [
                'user_id' => $user->id,
                'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
df -hT
echo "---"
du -sh /var/www/* 2>/dev/null || true
SH,
                'run_as_user' => null,
                'source' => Script::SOURCE_USER_CREATED,
                'marketplace_key' => null,
            ]
        );

        if ($org->default_site_script_id === null) {
            $org->update(['default_site_script_id' => $release->id]);
        }
    }
}
