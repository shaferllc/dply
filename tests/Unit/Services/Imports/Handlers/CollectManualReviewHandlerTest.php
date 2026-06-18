<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers\CollectManualReviewHandlerTest;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Imports\Services\Handlers\CollectManualReviewHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
function seedMigration(): ImportServerMigration
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
    ]);

    return ImportServerMigration::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider_credential_id' => $credential->id,
        'source' => 'ploi',
        'source_server_id' => 42,
        'status' => ImportServerMigration::STATUS_STAGING,
    ]);
}
test('emits custom nginx advisory when snapshot has nginx config', function () {
    $migration = seedMigration();
    ImportSiteMigration::create([
        'import_server_migration_id' => $migration->id,
        'source' => 'ploi',
        'source_site_id' => 100,
        'domain' => 'a.example.com',
        'site_type' => 'laravel',
        'status' => ImportSiteMigration::STATUS_COMPLETED,
        'source_snapshot' => [
            'nginx_config' => "location /custom { proxy_pass http://upstream; }\n",
        ],
    ]);

    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 90,
        'step_key' => ImportMigrationStep::KEY_COLLECT_MANUAL_REVIEW,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    (new CollectManualReviewHandler)->execute($step);

    $items = $migration->fresh()->manual_review_items;
    $kinds = array_map(fn ($i) => $i['kind'], $items);
    expect($kinds)->toContain('custom_nginx');

    $nginx = collect($items)->firstWhere('kind', 'custom_nginx');
    $this->assertStringContainsString('a.example.com', $nginx['title']);
    $this->assertStringContainsString('proxy_pass', $nginx['raw']['nginx_config']);

    // Baked-in server-level advisories always present.
    expect($kinds)->toContain('manual_advisory');
    expect($items)->toHaveCount(4, '1 nginx + 3 baked-in advisories');
});
test('emits php fpm and opcache advisories when present', function () {
    $migration = seedMigration();
    ImportSiteMigration::create([
        'import_server_migration_id' => $migration->id,
        'source' => 'ploi',
        'source_site_id' => 100,
        'domain' => 'a.example.com',
        'site_type' => 'laravel',
        'status' => ImportSiteMigration::STATUS_COMPLETED,
        'source_snapshot' => [
            'php_fpm_pool' => ['pm.max_children' => 50],
            'opcache' => ['memory_consumption' => 256],
        ],
    ]);
    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 90,
        'step_key' => ImportMigrationStep::KEY_COLLECT_MANUAL_REVIEW,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    (new CollectManualReviewHandler)->execute($step);

    $items = collect($migration->fresh()->manual_review_items);
    expect($items->firstWhere('kind', 'php_fpm_tuning'))->not->toBeNull();
    expect($items->firstWhere('kind', 'opcache_tuning'))->not->toBeNull();
});
test('baked in advisories appear even when no per site items', function () {
    $migration = seedMigration();
    ImportSiteMigration::create([
        'import_server_migration_id' => $migration->id,
        'source' => 'ploi',
        'source_site_id' => 100,
        'domain' => 'a.example.com',
        'site_type' => 'laravel',
        'status' => ImportSiteMigration::STATUS_COMPLETED,
        'source_snapshot' => [],
    ]);
    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 90,
        'step_key' => ImportMigrationStep::KEY_COLLECT_MANUAL_REVIEW,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    (new CollectManualReviewHandler)->execute($step);

    $items = $migration->fresh()->manual_review_items;
    expect($items)->toHaveCount(3);
    foreach ($items as $item) {
        expect($item['kind'])->toBe('manual_advisory');
        expect($item['dismissed_at'])->toBeNull();
    }
});
