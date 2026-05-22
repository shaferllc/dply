<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers\ApiOnlyHandlersTest;
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
use Illuminate\Support\Facades\Http;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);
/**
 * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: Site, 3: ImportServerMigration}
 */
function seedFixture(string $stepKey, string $siteType = 'laravel'): array
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
test('copy env stores env content on site', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/env' => Http::response([
            'data' => ['content' => "APP_ENV=production\nAPP_KEY=abc123\n"],
        ], 200),
    ]);

    [$step, , $site] = seedFixture(ImportMigrationStep::KEY_COPY_ENV);

    (new CopyEnvHandler())->execute($step);

    $site->refresh();
    expect($site->env_file_content)->toBe("APP_ENV=production\nAPP_KEY=abc123\n");
    expect($site->env_cache_origin)->toBe('import:ploi');
});
test('recreate crons creates server cron jobs', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/crons*' => Http::response([
            'data' => [
                ['id' => 1, 'frequency' => '* * * * *', 'command' => 'php artisan schedule:run', 'user' => 'ploi'],
                ['id' => 2, 'frequency' => '0 * * * *', 'command' => 'php artisan queue:work', 'user' => 'ploi'],
            ],
        ], 200),
    ]);

    [$step, , $site] = seedFixture(ImportMigrationStep::KEY_RECREATE_CRONS);

    (new RecreateCronsHandler())->execute($step);

    expect(ServerCronJob::query()->where('site_id', $site->id)->count())->toBe(2);
    $this->assertDatabaseHas('server_cron_jobs', [
        'site_id' => $site->id,
        'command' => 'php artisan schedule:run',
        'cron_expression' => '* * * * *',
    ]);
});
test('recreate crons is idempotent across reruns', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/crons*' => Http::response([
            'data' => [['id' => 7, 'frequency' => '*/5 * * * *', 'command' => 'php worker', 'user' => 'ploi']],
        ], 200),
    ]);

    [$step, , $site] = seedFixture(ImportMigrationStep::KEY_RECREATE_CRONS);

    (new RecreateCronsHandler())->execute($step);
    (new RecreateCronsHandler())->execute($step);

    expect(ServerCronJob::query()->where('site_id', $site->id)->count())->toBe(1);
});
test('recreate daemons creates site processes', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/daemons*' => Http::response([
            'data' => [
                ['id' => 1, 'name' => 'horizon', 'command' => '/home/ploi/app/artisan horizon', 'directory' => '/home/ploi/app', 'user' => 'ploi', 'processes' => 1],
            ],
        ], 200),
    ]);

    [$step, , $site] = seedFixture(ImportMigrationStep::KEY_RECREATE_DAEMONS);

    (new RecreateDaemonsHandler())->execute($step);

    $worker = SiteProcess::query()
        ->where('site_id', $site->id)
        ->where('type', SiteProcess::TYPE_WORKER)
        ->first();
    expect($worker)->not->toBeNull();
    expect($worker->name)->toBe('imported:horizon');

    $step->refresh();
    expect($step->result_data['warnings'])->not->toBeEmpty('Should flag /home/ploi/ path');
});
test('recreate scheduler creates scheduler when laravel with schedule run', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/crons*' => Http::response([
            'data' => [
                ['id' => 1, 'frequency' => '* * * * *', 'command' => 'cd /home/ploi/app && php artisan schedule:run', 'user' => 'ploi'],
            ],
        ], 200),
    ]);

    [$step, , $site] = seedFixture(ImportMigrationStep::KEY_RECREATE_SCHEDULER);

    (new RecreateSchedulerHandler())->execute($step);

    $scheduler = SiteProcess::query()
        ->where('site_id', $site->id)
        ->where('type', SiteProcess::TYPE_SCHEDULER)
        ->first();
    expect($scheduler)->not->toBeNull();
    expect($scheduler->command)->toBe('php artisan schedule:work');

    $step->refresh();
    expect($step->result_data['scheduler_created'])->toBeTrue();
});
test('recreate scheduler is a noop for non laravel sites', function () {
    [$step] = seedFixture(ImportMigrationStep::KEY_RECREATE_SCHEDULER, siteType: 'php');

    (new RecreateSchedulerHandler())->execute($step);

    $step->refresh();
    expect($step->result_data['scheduler_created'])->toBeFalse();
    expect($step->result_data['reason'])->toBe('not_laravel');
});
test('recreate scheduler skips when no schedule run cron on source', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/crons*' => Http::response([
            'data' => [['id' => 1, 'frequency' => '0 0 * * *', 'command' => 'php worker.php', 'user' => 'ploi']],
        ], 200),
    ]);

    [$step] = seedFixture(ImportMigrationStep::KEY_RECREATE_SCHEDULER);

    (new RecreateSchedulerHandler())->execute($step);

    $step->refresh();
    expect($step->result_data['scheduler_created'])->toBeFalse();
    expect($step->result_data['reason'])->toBe('no_scheduler_cron_on_source');
});
