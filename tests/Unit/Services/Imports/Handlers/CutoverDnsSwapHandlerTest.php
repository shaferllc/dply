<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers\CutoverDnsSwapHandlerTest;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Imports\Handlers\CutoverDnsSwapHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
/**
 * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: ImportServerMigration, 3: User, 4: Organization}
 */
function seedFixture(string $domain = 'app.example.com'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
    ]);
    $target = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'ip_address' => '198.51.100.10',
    ]);
    $site = Site::factory()->create([
        'server_id' => $target->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);
    $migration = ImportServerMigration::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider_credential_id' => $credential->id,
        'source' => 'ploi',
        'source_server_id' => 42,
        'target_server_id' => $target->id,
        'status' => ImportServerMigration::STATUS_CUTOVER_IN_PROGRESS,
    ]);
    $child = ImportSiteMigration::create([
        'import_server_migration_id' => $migration->id,
        'source' => 'ploi',
        'source_site_id' => 100,
        'domain' => $domain,
        'site_type' => 'laravel',
        'status' => ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS,
        'source_snapshot' => [],
        'target_site_id' => $site->id,
    ]);
    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $child->id,
        'sequence' => 60,
        'step_key' => ImportMigrationStep::KEY_CUTOVER_DNS_SWAP,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    return [$step, $child, $migration, $user, $org];
}
test('falls back to instructions when no dns automation in org', function () {
    Http::fake();
    [$step] = seedFixture();

    (new CutoverDnsSwapHandler)->execute($step);

    $step->refresh();
    expect($step->status)->toBe(ImportMigrationStep::STATUS_SKIPPED);
    expect($step->result_data['strategy'])->toBe('instructions');
    expect($step->result_data['records'][0]['value'])->toBe('198.51.100.10');
});
test('uses digitalocean adapter when zone hosted there', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/example.com' => Http::response([
            'domain' => ['name' => 'example.com', 'ttl' => 1800],
        ], 200),
        'https://api.digitalocean.com/v2/domains/example.com/records' => Http::response([
            'domain_record' => ['id' => 7777, 'type' => 'A', 'name' => 'app', 'data' => '198.51.100.10'],
        ], 201),
    ]);
    [$step, , , $user, $org] = seedFixture();
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);

    (new CutoverDnsSwapHandler)->execute($step);

    $step->refresh();
    expect($step->result_data['strategy'])->toBe('automated');
    expect($step->result_data['credential'])->toBe('digitalocean');
    expect($step->result_data['zone'])->toBe('example.com');
    expect($step->result_data['record'])->toBe('app');
    expect($step->result_data['record_id'])->toBe(7777);

    Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
        && str_contains($req->url(), '/domains/example.com/records')
        && $req['type'] === 'A'
        && $req['name'] === 'app'
        && $req['data'] === '198.51.100.10');
});
test('skips dns credential when zone not in account', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/example.com' => Http::response(['message' => 'not found'], 404),
    ]);
    [$step, , , $user, $org] = seedFixture();
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);

    (new CutoverDnsSwapHandler)->execute($step);

    $step->refresh();
    expect($step->status)->toBe(ImportMigrationStep::STATUS_SKIPPED, 'No matching zone → instructions fallback');
});
test('extracts apex from multi label subdomain', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/example.co.uk' => Http::response([
            'domain' => ['name' => 'example.co.uk'],
        ], 200),
        'https://api.digitalocean.com/v2/domains/example.co.uk/records' => Http::response([
            'domain_record' => ['id' => 1, 'type' => 'A', 'name' => 'staging.app', 'data' => '198.51.100.10'],
        ], 201),
    ]);
    [$step, , , $user, $org] = seedFixture('staging.app.example.co.uk');
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);

    (new CutoverDnsSwapHandler)->execute($step);

    $step->refresh();
    expect($step->result_data['zone'])->toBe('example.co.uk');
    expect($step->result_data['record'])->toBe('staging.app');
});
