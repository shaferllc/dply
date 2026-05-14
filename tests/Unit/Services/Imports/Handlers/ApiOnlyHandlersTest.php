<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\User;
use App\Services\Imports\Handlers\CopyEnvHandler;
use App\Services\Imports\Handlers\RecreateCronsHandler;
use App\Services\Imports\Handlers\RecreateDaemonsHandler;
use App\Services\Imports\Handlers\RecreateSchedulerHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "API-only" handlers don't SSH — they read from Ploi via API + write to
 * dply via DB. Easy unit tests via Http::fake.
 */
class ApiOnlyHandlersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: Site, 3: ImportServerMigration}
     */
    protected function seedFixture(string $stepKey, string $siteType = 'laravel'): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_token'],
        ]);
        $target = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $target->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'slug' => 'acme-app',
            'name' => 'app.example.com',
        ]);
        $migration = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'target_server_id' => $target->id,
            'status' => ImportServerMigration::STATUS_STAGING,
        ]);
        $child = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => 'ploi',
            'source_site_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => $siteType,
            'status' => ImportSiteMigration::STATUS_STAGING,
            'source_snapshot' => [],
            'target_site_id' => $site->id,
        ]);
        $step = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $child->id,
            'sequence' => 1,
            'step_key' => $stepKey,
            'status' => ImportMigrationStep::STATUS_RUNNING,
        ]);

        return [$step, $child, $site, $migration];
    }

    public function test_copy_env_stores_env_content_on_site(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/env' => Http::response([
                'data' => ['content' => "APP_ENV=production\nAPP_KEY=abc123\n"],
            ], 200),
        ]);

        [$step, , $site] = $this->seedFixture(ImportMigrationStep::KEY_COPY_ENV);

        (new CopyEnvHandler())->execute($step);

        $site->refresh();
        $this->assertSame("APP_ENV=production\nAPP_KEY=abc123\n", $site->env_file_content);
        $this->assertSame('import:ploi', $site->env_cache_origin);
    }

    public function test_recreate_crons_creates_server_cron_jobs(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/crons*' => Http::response([
                'data' => [
                    ['id' => 1, 'frequency' => '* * * * *', 'command' => 'php artisan schedule:run', 'user' => 'ploi'],
                    ['id' => 2, 'frequency' => '0 * * * *', 'command' => 'php artisan queue:work', 'user' => 'ploi'],
                ],
            ], 200),
        ]);

        [$step, , $site] = $this->seedFixture(ImportMigrationStep::KEY_RECREATE_CRONS);

        (new RecreateCronsHandler())->execute($step);

        $this->assertSame(2, ServerCronJob::query()->where('site_id', $site->id)->count());
        $this->assertDatabaseHas('server_cron_jobs', [
            'site_id' => $site->id,
            'command' => 'php artisan schedule:run',
            'cron_expression' => '* * * * *',
        ]);
    }

    public function test_recreate_crons_is_idempotent_across_reruns(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/crons*' => Http::response([
                'data' => [['id' => 7, 'frequency' => '*/5 * * * *', 'command' => 'php worker', 'user' => 'ploi']],
            ], 200),
        ]);

        [$step, , $site] = $this->seedFixture(ImportMigrationStep::KEY_RECREATE_CRONS);

        (new RecreateCronsHandler())->execute($step);
        (new RecreateCronsHandler())->execute($step);

        $this->assertSame(1, ServerCronJob::query()->where('site_id', $site->id)->count());
    }

    public function test_recreate_daemons_creates_site_processes(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/daemons*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'horizon', 'command' => '/home/ploi/app/artisan horizon', 'directory' => '/home/ploi/app', 'user' => 'ploi', 'processes' => 1],
                ],
            ], 200),
        ]);

        [$step, , $site] = $this->seedFixture(ImportMigrationStep::KEY_RECREATE_DAEMONS);

        (new RecreateDaemonsHandler())->execute($step);

        $worker = SiteProcess::query()
            ->where('site_id', $site->id)
            ->where('type', SiteProcess::TYPE_WORKER)
            ->first();
        $this->assertNotNull($worker);
        $this->assertSame('imported:horizon', $worker->name);

        $step->refresh();
        $this->assertNotEmpty($step->result_data['warnings'], 'Should flag /home/ploi/ path');
    }

    public function test_recreate_scheduler_creates_scheduler_when_laravel_with_schedule_run(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/crons*' => Http::response([
                'data' => [
                    ['id' => 1, 'frequency' => '* * * * *', 'command' => 'cd /home/ploi/app && php artisan schedule:run', 'user' => 'ploi'],
                ],
            ], 200),
        ]);

        [$step, , $site] = $this->seedFixture(ImportMigrationStep::KEY_RECREATE_SCHEDULER);

        (new RecreateSchedulerHandler())->execute($step);

        $scheduler = SiteProcess::query()
            ->where('site_id', $site->id)
            ->where('type', SiteProcess::TYPE_SCHEDULER)
            ->first();
        $this->assertNotNull($scheduler);
        $this->assertSame('php artisan schedule:work', $scheduler->command);

        $step->refresh();
        $this->assertTrue($step->result_data['scheduler_created']);
    }

    public function test_recreate_scheduler_is_a_noop_for_non_laravel_sites(): void
    {
        [$step] = $this->seedFixture(ImportMigrationStep::KEY_RECREATE_SCHEDULER, siteType: 'php');

        (new RecreateSchedulerHandler())->execute($step);

        $step->refresh();
        $this->assertFalse($step->result_data['scheduler_created']);
        $this->assertSame('not_laravel', $step->result_data['reason']);
    }

    public function test_recreate_scheduler_skips_when_no_schedule_run_cron_on_source(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/crons*' => Http::response([
                'data' => [['id' => 1, 'frequency' => '0 0 * * *', 'command' => 'php worker.php', 'user' => 'ploi']],
            ], 200),
        ]);

        [$step] = $this->seedFixture(ImportMigrationStep::KEY_RECREATE_SCHEDULER);

        (new RecreateSchedulerHandler())->execute($step);

        $step->refresh();
        $this->assertFalse($step->result_data['scheduler_created']);
        $this->assertSame('no_scheduler_cron_on_source', $step->result_data['reason']);
    }
}
