<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDatabaseCommandsTest;

use App\Enums\SiteType;
use App\Jobs\AttachCloudDatabaseJob;
use App\Jobs\ProvisionCloudDatabaseJob;
use App\Jobs\TeardownCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function orgWithDoCredential(): Organization
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 'tok'],
    ]);

    return $org;
}
function containerSite(Organization $org): Site
{
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);
}
test('create command creates database and queues job', function () {
    Bus::fake();
    $org = orgWithDoCredential();

    $exit = Artisan::call('dply:cloud:db:create', [
        '--name' => 'acme-db',
        '--engine' => 'postgres',
        '--size' => 'medium',
        '--org' => $org->id,
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('cloud_databases', ['name' => 'acme-db', 'engine' => 'postgres', 'size' => 'medium']);
    Bus::assertDispatched(ProvisionCloudDatabaseJob::class);
});
test('create command fails on unknown engine', function () {
    $org = orgWithDoCredential();

    $exit = Artisan::call('dply:cloud:db:create', [
        '--name' => 'x',
        '--engine' => 'oracle',
        '--org' => $org->id,
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Unknown engine', Artisan::output());
});
test('create command fails without credential', function () {
    $org = Organization::factory()->create();

    $exit = Artisan::call('dply:cloud:db:create', [
        '--name' => 'x',
        '--engine' => 'postgres',
        '--org' => $org->id,
    ]);

    expect($exit)->toBe(1);
});
test('list command json', function () {
    $org = orgWithDoCredential();
    CloudDatabase::factory()->create(['organization_id' => $org->id, 'name' => 'pg-one']);
    CloudDatabase::factory()->mysql()->create(['organization_id' => $org->id, 'name' => 'my-one']);

    $exit = Artisan::call('dply:cloud:db:list', ['--json' => true]);
    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true);
    expect($payload['total'])->toBe(2);
});
test('list command filters by engine', function () {
    $org = orgWithDoCredential();
    CloudDatabase::factory()->create(['organization_id' => $org->id, 'name' => 'pg-one']);
    CloudDatabase::factory()->mysql()->create(['organization_id' => $org->id, 'name' => 'my-one']);

    Artisan::call('dply:cloud:db:list', ['--json' => true, '--engine' => 'mysql']);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['total'])->toBe(1);
    expect($payload['databases'][0]['name'])->toBe('my-one');
});
test('list command rejects unknown status', function () {
    $exit = Artisan::call('dply:cloud:db:list', ['--status' => 'bogus']);
    expect($exit)->toBe(1);
    $this->assertStringContainsString('Unknown --status', Artisan::output());
});
test('list command empty message', function () {
    $exit = Artisan::call('dply:cloud:db:list');
    expect($exit)->toBe(0);
    $this->assertStringContainsString('No managed databases found', Artisan::output());
});
test('attach command queues job', function () {
    Bus::fake();
    $org = orgWithDoCredential();
    $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
    $site = containerSite($org);

    $exit = Artisan::call('dply:cloud:db:attach', ['database' => $db->name, 'site' => $site->id]);

    expect($exit)->toBe(0);
    Bus::assertDispatched(AttachCloudDatabaseJob::class, fn ($j) => ! $j->detach && $j->siteId === $site->id);
});
test('attach command rejects inactive database', function () {
    Bus::fake();
    $org = orgWithDoCredential();
    $db = CloudDatabase::factory()->create(['organization_id' => $org->id]);
    // provisioning
    $site = containerSite($org);

    $exit = Artisan::call('dply:cloud:db:attach', ['database' => $db->name, 'site' => $site->id]);

    expect($exit)->toBe(1);
    Bus::assertNotDispatched(AttachCloudDatabaseJob::class);
});
test('attach command rejects non container site', function () {
    Bus::fake();
    $org = orgWithDoCredential();
    $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
    $server = Server::factory()->ready()->create();
    $site = Site::factory()->create(['server_id' => $server->id, 'type' => SiteType::Php]);

    $exit = Artisan::call('dply:cloud:db:attach', ['database' => $db->name, 'site' => $site->id]);

    expect($exit)->toBe(1);
});
test('detach command queues job in detach mode', function () {
    Bus::fake();
    $org = orgWithDoCredential();
    $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
    $site = containerSite($org);

    $exit = Artisan::call('dply:cloud:db:detach', ['database' => $db->name, 'site' => $site->id]);

    expect($exit)->toBe(0);
    Bus::assertDispatched(AttachCloudDatabaseJob::class, fn ($j) => $j->detach === true);
});
test('attach command fails on unknown database', function () {
    Bus::fake();
    $exit = Artisan::call('dply:cloud:db:attach', ['database' => 'nope', 'site' => 'nope']);
    expect($exit)->toBe(1);
});
test('teardown command queues job', function () {
    Bus::fake();
    $org = orgWithDoCredential();
    $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);

    $exit = Artisan::call('dply:cloud:db:teardown', ['database' => $db->id]);

    expect($exit)->toBe(0);
    Bus::assertDispatched(TeardownCloudDatabaseJob::class, fn ($j) => $j->cloudDatabaseId === $db->id);
});
test('teardown command fails on unknown database', function () {
    Bus::fake();
    $exit = Artisan::call('dply:cloud:db:teardown', ['database' => 'nope']);
    expect($exit)->toBe(1);
    Bus::assertNotDispatched(TeardownCloudDatabaseJob::class);
});
