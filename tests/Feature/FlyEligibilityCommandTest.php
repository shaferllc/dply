<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FlyEligibilityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_node_and_static_sites_excluding_php(): void
    {
        [$user, $orgA, $orgB] = $this->makeTwoOrgs();
        $serverA = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $orgA->id]);
        $serverB = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $orgB->id]);

        Site::factory()->create([
            'name' => 'Marketing site',
            'server_id' => $serverA->id,
            'user_id' => $user->id,
            'organization_id' => $orgA->id,
            'runtime' => 'node',
        ]);
        Site::factory()->create([
            'name' => 'Docs landing',
            'server_id' => $serverB->id,
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'runtime' => 'static',
        ]);
        Site::factory()->create([
            'name' => 'Laravel admin',
            'server_id' => $serverA->id,
            'user_id' => $user->id,
            'organization_id' => $orgA->id,
            'runtime' => 'php',
        ]);

        $payload = $this->runJsonCommand([]);

        $this->assertSame(2, $payload['total_eligible']);
        $names = array_column($payload['sites'], 'site');
        $this->assertContains('Marketing site', $names);
        $this->assertContains('Docs landing', $names);
        $this->assertNotContains('Laravel admin', $names);
    }

    public function test_json_payload_shape(): void
    {
        [$user, $orgA, $orgB] = $this->makeTwoOrgs();
        $serverA = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $orgA->id]);

        Site::factory()->create([
            'name' => 'NodeApp',
            'server_id' => $serverA->id,
            'user_id' => $user->id,
            'organization_id' => $orgA->id,
            'runtime' => 'node',
        ]);
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'provider' => 'fly_io',
            'name' => 'Fly',
            'credentials' => ['api_token' => 't'],
        ]);

        $payload = $this->runJsonCommand([]);

        $this->assertSame(1, $payload['total_eligible']);
        $this->assertSame(1, $payload['orgs_connected_to_fly']);
        $this->assertSame('NodeApp', $payload['sites'][0]['site']);
        $this->assertFalse($payload['sites'][0]['org_has_fly']);
    }

    public function test_connected_only_filters_to_orgs_with_fly(): void
    {
        [$user, $orgA, $orgB] = $this->makeTwoOrgs();
        $serverA = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $orgA->id]);
        $serverB = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $orgB->id]);

        Site::factory()->create([
            'name' => 'Disconnected node',
            'server_id' => $serverA->id,
            'user_id' => $user->id,
            'organization_id' => $orgA->id,
            'runtime' => 'node',
        ]);
        Site::factory()->create([
            'name' => 'Connected static',
            'server_id' => $serverB->id,
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'runtime' => 'static',
        ]);
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'provider' => 'fly_io',
            'name' => 'Fly',
            'credentials' => ['api_token' => 't'],
        ]);

        $payload = $this->runJsonCommand(['--connected-only' => true]);

        $this->assertSame(1, $payload['total_eligible']);
        $names = array_column($payload['sites'], 'site');
        $this->assertSame(['Connected static'], $names);
    }

    public function test_no_eligible_sites_message(): void
    {
        $this->artisan('dply:fly:eligibility')
            ->expectsOutputToContain('No eligible sites found')
            ->assertExitCode(0);
    }

    /**
     * @param  array<string, mixed>  $extraOptions
     * @return array<string, mixed>
     */
    private function runJsonCommand(array $extraOptions): array
    {
        $exit = Artisan::call(
            'dply:fly:eligibility',
            array_merge(['--json' => true], $extraOptions),
        );
        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Command JSON should decode: '.$output);

        return $decoded;
    }

    /**
     * @return array{0: User, 1: Organization, 2: Organization}
     */
    private function makeTwoOrgs(): array
    {
        $user = User::factory()->create();
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();
        $a->users()->attach($user->id, ['role' => 'owner']);
        $b->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $a, $b];
    }
}
