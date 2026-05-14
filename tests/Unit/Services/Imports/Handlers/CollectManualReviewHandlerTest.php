<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\Imports\Handlers\CollectManualReviewHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectManualReviewHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function seedMigration(): ImportServerMigration
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

    public function test_emits_custom_nginx_advisory_when_snapshot_has_nginx_config(): void
    {
        $migration = $this->seedMigration();
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

        (new CollectManualReviewHandler())->execute($step);

        $items = $migration->fresh()->manual_review_items;
        $kinds = array_map(fn ($i) => $i['kind'], $items);
        $this->assertContains('custom_nginx', $kinds);

        $nginx = collect($items)->firstWhere('kind', 'custom_nginx');
        $this->assertStringContainsString('a.example.com', $nginx['title']);
        $this->assertStringContainsString('proxy_pass', $nginx['raw']['nginx_config']);

        // Baked-in server-level advisories always present.
        $this->assertContains('manual_advisory', $kinds);
        $this->assertCount(4, $items, '1 nginx + 3 baked-in advisories');
    }

    public function test_emits_php_fpm_and_opcache_advisories_when_present(): void
    {
        $migration = $this->seedMigration();
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

        (new CollectManualReviewHandler())->execute($step);

        $items = collect($migration->fresh()->manual_review_items);
        $this->assertNotNull($items->firstWhere('kind', 'php_fpm_tuning'));
        $this->assertNotNull($items->firstWhere('kind', 'opcache_tuning'));
    }

    public function test_baked_in_advisories_appear_even_when_no_per_site_items(): void
    {
        $migration = $this->seedMigration();
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

        (new CollectManualReviewHandler())->execute($step);

        $items = $migration->fresh()->manual_review_items;
        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertSame('manual_advisory', $item['kind']);
            $this->assertNull($item['dismissed_at']);
        }
    }
}
