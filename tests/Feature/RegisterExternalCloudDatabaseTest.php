<?php

declare(strict_types=1);

namespace Tests\Feature\RegisterExternalCloudDatabaseTest;

use App\Livewire\Cloud\Create as CloudCreate;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Cloud\Actions\ApplyCloudSiteExtras;
use App\Modules\Cloud\Actions\CreateCloudSite;
use App\Modules\Cloud\Actions\RegisterExternalCloudDatabase;
use App\Modules\Database\Jobs\TeardownCloudDatabaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);
uses(WithFeatures::class);
usesFeatures('surface.cloud', 'provider.aws_app_runner');

test('registers external postgres as active cloud database', function () {
    $org = Organization::factory()->create();

    $db = (new RegisterExternalCloudDatabase)->handle($org, [
        'name' => 'rds-main',
        'engine' => 'postgres',
        'host' => 'db.xxxx.us-east-1.rds.amazonaws.com',
        'port' => 5432,
        'database' => 'app',
        'username' => 'appuser',
        'password' => 's3cret',
        'ssl' => true,
    ]);

    expect($db->backend)->toBe(CloudDatabase::BACKEND_EXTERNAL);
    expect($db->status)->toBe(CloudDatabase::STATUS_ACTIVE);
    expect($db->isExternal())->toBeTrue();
    expect($db->connectionEnvVars())->toMatchArray([
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'db.xxxx.us-east-1.rds.amazonaws.com',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'app',
        'DB_USERNAME' => 'appuser',
        'DB_PASSWORD' => 's3cret',
        'DB_SSLMODE' => 'require',
    ]);
});

test('teardown external database does not call digitalocean', function () {
    Http::fake();
    $org = Organization::factory()->create();
    $db = (new RegisterExternalCloudDatabase)->handle($org, [
        'name' => 'external-1',
        'engine' => 'mysql',
        'host' => 'mysql.example.com',
        'port' => 3306,
        'database' => 'app',
        'username' => 'root',
        'password' => 'pw',
    ]);

    (new TeardownCloudDatabaseJob($db->id))->handle();

    $this->assertDatabaseMissing('cloud_databases', ['id' => $db->id]);
    Http::assertNothingSent();
});

test('apply extras registers external db and merges env for aws site', function () {
    Queue::fake();
    config(['server_providers.enabled.aws_app_runner' => true]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws_app_runner',
        'name' => 'AWS',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1'],
    ]);

    $site = (new CreateCloudSite)->handle($user, $org, [
        'name' => 'api-aws',
        'image' => 'public.ecr.aws/acme/api:v1',
        'port' => 8080,
        'region' => 'us-east-1',
        'backend' => 'aws_app_runner',
    ]);

    (new ApplyCloudSiteExtras)->handle($site, [
        'databases' => [[
            'mode' => 'external',
            'name' => 'app-db',
            'engine' => 'postgres',
            'host' => 'db.example.com',
            'port' => 5432,
            'database' => 'app',
            'username' => 'app',
            'password' => 'pw',
            'ssl' => true,
            'env_prefix' => 'DB',
        ]],
    ]);

    $site->refresh();
    expect($site->env_file_content)->toContain('DB_HOST=db.example.com');
    expect($site->env_file_content)->toContain('DB_SSLMODE=require');

    $db = CloudDatabase::query()->where('name', 'app-db')->first();
    expect($db)->not->toBeNull();
    expect($db->backend)->toBe(CloudDatabase::BACKEND_EXTERNAL);
    expect($db->sites()->where('sites.id', $site->id)->exists())->toBeTrue();
});

test('aws create form defaults new databases to external mode', function () {
    config(['server_providers.enabled.aws_app_runner' => true]);
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws_app_runner',
        'name' => 'AWS',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1'],
    ]);

    Livewire::actingAs($user)
        ->test(CloudCreate::class)
        ->set('backend', 'aws_app_runner')
        ->call('addDatabase', 'postgres')
        ->assertSet('databases.0.mode', 'external')
        ->assertSee('Connect external')
        ->assertSee('reachable from App Runner');
});
