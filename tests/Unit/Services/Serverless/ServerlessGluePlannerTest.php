<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Serverless;

use App\Models\EdgeDeployHook;
use App\Models\FunctionAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Services\ServerlessGluePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('planner marks edge webhook recipe available with hooks and actions', function () {
    [$org] = orgWithGlueInventory();

    $recipe = app(ServerlessGluePlanner::class)->recipe($org, 'edge_webhook_pipeline');

    expect($recipe)->not->toBeNull();
    expect($recipe->available)->toBeTrue();
    expect($recipe->resources)->not->toBeEmpty();
});

test('planner flags cloud recipe unavailable without cloud app', function () {
    [$org, $server] = orgWithGlueInventory(includeCloud: false);

    $recipe = app(ServerlessGluePlanner::class)->recipe($org, 'cloud_redeploy_chain');

    expect($recipe)->not->toBeNull();
    expect($recipe->available)->toBeFalse();
});

test('inventory lists sequences and glue endpoints', function () {
    [$org, $server, $site] = orgWithGlueInventory();

    $sequence = FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => 'pipeline',
        'kind' => FunctionAction::KIND_SEQUENCE,
        'components' => [
            ['id' => 'a', 'name' => 'fetch'],
            ['id' => 'b', 'name' => 'notify'],
        ],
    ]);

    $recipe = app(ServerlessGluePlanner::class)->recipe($org, 'edge_webhook_pipeline');

    expect(collect($recipe->gaps)->contains(fn (string $gap): bool => str_contains($gap, 'sequences')))->toBeFalse();
    expect($sequence->isSequence())->toBeTrue();
});

/**
 * @return array{0: Organization, 1: Server, 2: Site}
 */
function orgWithGlueInventory(bool $includeCloud = true): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $functionsServer = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => [
                'api_host' => 'https://faas-nyc1.example.com',
                'access_key' => 'keyid:keysecret',
            ],
        ],
    ]);

    $functionSite = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $functionsServer->id,
        'user_id' => $user->id,
        'name' => 'Notifier',
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ]);

    FunctionAction::query()->create([
        'site_id' => $functionSite->id,
        'name' => 'fetch',
        'kind' => FunctionAction::KIND_CODE,
        'runtime' => 'nodejs:18',
    ]);

    FunctionAction::query()->create([
        'site_id' => $functionSite->id,
        'name' => 'notify',
        'kind' => FunctionAction::KIND_CODE,
        'runtime' => 'nodejs:18',
    ]);

    $edgeSite = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id])->id,
        'user_id' => $user->id,
        'name' => 'Marketing Edge',
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'static']],
    ]);

    EdgeDeployHook::query()->create([
        'site_id' => $edgeSite->id,
        'name' => 'Deploy hook',
        'token_hash' => hash('sha256', 'test-token'),
        'token_prefix' => 'testtok',
    ]);

    if ($includeCloud) {
        Site::factory()->create([
            'organization_id' => $org->id,
            'server_id' => Server::factory()->create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
            ])->id,
            'user_id' => $user->id,
            'name' => 'Cloud API',
            'container_backend' => 'dply_cloud',
            'meta' => ['container' => ['live_url' => 'https://api.example.com']],
        ]);
    }

    $byoServer = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    ServerCronJob::query()->create([
        'server_id' => $byoServer->id,
        'cron_expression' => '0 * * * *',
        'command' => 'php artisan schedule:run',
        'user' => 'deploy',
        'enabled' => true,
    ]);

    return [$org, $functionsServer, $functionSite];
}
