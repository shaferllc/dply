<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Cloud\CreateCloudDatabase;
use App\Jobs\ProvisionCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CreateCloudDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function orgWithDoCredential(string $provider = 'digitalocean'): Organization
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => $provider,
            'name' => 'DO',
            'credentials' => ['api_token' => 'tok'],
        ]);

        return $org;
    }

    public function test_creates_provisioning_row_and_dispatches_job(): void
    {
        Bus::fake();
        $org = $this->orgWithDoCredential();

        $db = (new CreateCloudDatabase)->handle($org, [
            'name' => 'acme-db',
            'engine' => 'postgres',
            'version' => '16',
            'size' => 'medium',
            'region' => 'nyc1',
        ]);

        $this->assertSame('acme-db', $db->name);
        $this->assertSame(CloudDatabase::STATUS_PROVISIONING, $db->status);
        $this->assertSame('medium', $db->size);
        $this->assertSame(CloudDatabase::BACKEND_DIGITALOCEAN, $db->backend);
        $this->assertNotNull($db->provider_credential_id);

        Bus::assertDispatched(ProvisionCloudDatabaseJob::class, fn ($j) => $j->cloudDatabaseId === $db->id);
    }

    public function test_accepts_app_platform_credential_as_fallback(): void
    {
        Bus::fake();
        $org = $this->orgWithDoCredential('digitalocean_app_platform');

        $db = (new CreateCloudDatabase)->handle($org, [
            'name' => 'fallback-db',
            'engine' => 'mysql',
        ]);

        $this->assertNotNull($db->provider_credential_id);
    }

    public function test_rejects_unknown_engine(): void
    {
        $org = $this->orgWithDoCredential();

        $this->expectException(\InvalidArgumentException::class);
        (new CreateCloudDatabase)->handle($org, ['name' => 'x', 'engine' => 'oracle']);
    }

    public function test_rejects_missing_name(): void
    {
        $org = $this->orgWithDoCredential();

        $this->expectException(\InvalidArgumentException::class);
        (new CreateCloudDatabase)->handle($org, ['name' => '', 'engine' => 'postgres']);
    }

    public function test_fails_without_a_do_credential(): void
    {
        $org = Organization::factory()->create();

        $this->expectException(\RuntimeException::class);
        (new CreateCloudDatabase)->handle($org, ['name' => 'x', 'engine' => 'postgres']);
    }

    public function test_unknown_size_falls_back_to_small(): void
    {
        Bus::fake();
        $org = $this->orgWithDoCredential();

        $db = (new CreateCloudDatabase)->handle($org, [
            'name' => 'x',
            'engine' => 'postgres',
            'size' => 'enormous',
        ]);

        $this->assertSame('small', $db->size);
    }
}
