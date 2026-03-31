<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Actions\Servers\BuildProviderCredentialHealth;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BuildProviderCredentialHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_ok_when_provider_validation_succeeds(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/account' => Http::response(['account' => ['uuid' => 'abc']], 200),
        ]);

        $credential = $this->digitalOceanCredential();

        $result = BuildProviderCredentialHealth::run('digitalocean', $credential);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('info', $result['severity']);
    }

    public function test_reports_under_scoped_when_provider_rejects_access(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/account' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        $credential = $this->digitalOceanCredential();

        $result = BuildProviderCredentialHealth::run('digitalocean', $credential);

        $this->assertSame('under_scoped', $result['status']);
        $this->assertSame('error', $result['severity']);
    }

    public function test_reports_rate_limited_when_provider_is_rate_limited(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/account' => Http::response(['message' => 'Rate limit exceeded'], 429),
        ]);

        $credential = $this->digitalOceanCredential();

        $result = BuildProviderCredentialHealth::run('digitalocean', $credential);

        $this->assertSame('rate_limited', $result['status']);
        $this->assertSame('warning', $result['severity']);
    }

    private function digitalOceanCredential(): ProviderCredential
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        return ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => 'Primary DO',
            'credentials' => ['api_token' => 'dop_v1_test'],
        ]);
    }
}
