<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DatabaseUrlAliasTest;

use App\Models\Site;
use App\Modules\Deploy\Services\Concerns\ManagesDatabaseBindings;

/**
 * DATABASE_URL is a Laravel/Rails convention. A linked database produced a
 * correct connection URL that a Payload app could not see — it reads
 * DATABASE_URI, and @payloadcms/db-postgres does not fall back — so the
 * auto-detected `payload migrate` step failed with no database configured.
 */
function aliasesFor(?string $migrationTool): array
{
    $site = new Site;
    $site->meta = $migrationTool === null
        ? []
        : ['vm_runtime' => ['detected' => ['language' => 'node', 'framework' => 'nextjs', 'migration_tool' => $migrationTool]]];

    $m = new \ReflectionMethod(ManagesDatabaseBindings::class, 'databaseUrlAliasesFor');
    $m->setAccessible(true);

    return $m->invoke(null, $site);
}

test('a payload app gets DATABASE_URI', function () {
    expect(aliasesFor('payload'))->toBe(['DATABASE_URI']);
});

test('prisma and drizzle need no alias', function () {
    // Both already read DATABASE_URL, which the binding sets directly.
    expect(aliasesFor('prisma'))->toBe([])
        ->and(aliasesFor('drizzle'))->toBe([]);
});

test('an unrecognised stack gets no aliases', function () {
    // Inventing env names an app never reads is noise at best, and can shadow
    // something the operator set deliberately.
    expect(aliasesFor(null))->toBe([])
        ->and(aliasesFor('something-else'))->toBe([]);
});
