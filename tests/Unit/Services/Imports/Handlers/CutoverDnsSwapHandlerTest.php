<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers;

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
use Tests\TestCase;

class CutoverDnsSwapHandlerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: ImportServerMigration, 3: User, 4: Organization}
     */
    protected function seedFixture(string $domain = 'app.example.com'): array
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

    public function test_falls_back_to_instructions_when_no_dns_automation_in_org(): void
    {
        Http::fake();
        [$step] = $this->seedFixture();

        (new CutoverDnsSwapHandler())->execute($step);

        $step->refresh();
        $this->assertSame(ImportMigrationStep::STATUS_SKIPPED, $step->status);
        $this->assertSame('instructions', $step->result_data['strategy']);
        $this->assertSame('198.51.100.10', $step->result_data['records'][0]['value']);
    }

    public function test_uses_digitalocean_adapter_when_zone_hosted_there(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/domains/example.com' => Http::response([
                'domain' => ['name' => 'example.com', 'ttl' => 1800],
            ], 200),
            'https://api.digitalocean.com/v2/domains/example.com/records' => Http::response([
                'domain_record' => ['id' => 7777, 'type' => 'A', 'name' => 'app', 'data' => '198.51.100.10'],
            ], 201),
        ]);
        [$step, , , $user, $org] = $this->seedFixture();
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'dop_v1_test'],
        ]);

        (new CutoverDnsSwapHandler())->execute($step);

        $step->refresh();
        $this->assertSame('automated', $step->result_data['strategy']);
        $this->assertSame('digitalocean', $step->result_data['credential']);
        $this->assertSame('example.com', $step->result_data['zone']);
        $this->assertSame('app', $step->result_data['record']);
        $this->assertSame(7777, $step->result_data['record_id']);

        Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
            && str_contains($req->url(), '/domains/example.com/records')
            && $req['type'] === 'A'
            && $req['name'] === 'app'
            && $req['data'] === '198.51.100.10');
    }

    public function test_skips_dns_credential_when_zone_not_in_account(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/domains/example.com' => Http::response(['message' => 'not found'], 404),
        ]);
        [$step, , , $user, $org] = $this->seedFixture();
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'dop_v1_test'],
        ]);

        (new CutoverDnsSwapHandler())->execute($step);

        $step->refresh();
        $this->assertSame(ImportMigrationStep::STATUS_SKIPPED, $step->status, 'No matching zone → instructions fallback');
    }

    public function test_extracts_apex_from_multi_label_subdomain(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/domains/example.co.uk' => Http::response([
                'domain' => ['name' => 'example.co.uk'],
            ], 200),
            'https://api.digitalocean.com/v2/domains/example.co.uk/records' => Http::response([
                'domain_record' => ['id' => 1, 'type' => 'A', 'name' => 'staging.app', 'data' => '198.51.100.10'],
            ], 201),
        ]);
        [$step, , , $user, $org] = $this->seedFixture('staging.app.example.co.uk');
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'dop_v1_test'],
        ]);

        (new CutoverDnsSwapHandler())->execute($step);

        $step->refresh();
        $this->assertSame('example.co.uk', $step->result_data['zone']);
        $this->assertSame('staging.app', $step->result_data['record']);
    }
}
