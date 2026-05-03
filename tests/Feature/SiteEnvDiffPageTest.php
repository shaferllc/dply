<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteEnvDiffPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_in_sync_when_environments_match(): void
    {
        [$user, $server, $site] = $this->makeUserSite();
        $this->seedVar($site, 'A', 'one', 'production');
        $this->seedVar($site, 'A', 'one', 'staging');

        $response = $this->actingAs($user)->get(route('sites.env-diff', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertSee('in sync', false);
    }

    public function test_categorizes_keys_into_three_buckets(): void
    {
        [$user, $server, $site] = $this->makeUserSite();
        $this->seedVar($site, 'PROD_ONLY', 'p', 'production');
        $this->seedVar($site, 'SHARED', 'prod-value', 'production');
        $this->seedVar($site, 'STAGING_ONLY', 's', 'staging');
        $this->seedVar($site, 'SHARED', 'staging-value', 'staging');

        $response = $this->actingAs($user)->get(route('sites.env-diff', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertSee('PROD_ONLY')
            ->assertSee('STAGING_ONLY')
            ->assertSee('SHARED')
            ->assertSee('Differs in value');
    }

    public function test_masks_values_by_default(): void
    {
        [$user, $server, $site] = $this->makeUserSite();
        $this->seedVar($site, 'API_KEY', 'super-prod-secret', 'production');
        $this->seedVar($site, 'API_KEY', 'super-stage-secret', 'staging');

        $response = $this->actingAs($user)->get(route('sites.env-diff', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertDontSee('super-prod-secret')
            ->assertDontSee('super-stage-secret')
            ->assertSee('•');
    }

    public function test_url_params_select_environments(): void
    {
        [$user, $server, $site] = $this->makeUserSite();
        $this->seedVar($site, 'DEV_ONLY', 'd', 'development');

        $response = $this->actingAs($user)->get(route('sites.env-diff', [
            'server' => $server,
            'site' => $site,
        ]).'?from=production&to=development');

        $response->assertOk()
            ->assertSee('DEV_ONLY')
            ->assertSee('development');
    }

    public function test_same_from_and_to_emits_warning(): void
    {
        [$user, $server, $site] = $this->makeUserSite();

        $response = $this->actingAs($user)->get(route('sites.env-diff', [
            'server' => $server,
            'site' => $site,
        ]).'?from=production&to=production');

        $response->assertOk()
            ->assertSee('From and To are the same');
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makeUserSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        return [$user, $server, $site];
    }

    private function seedVar(Site $site, string $key, string $value, string $environment): void
    {
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => $key,
            'env_value' => $value,
            'environment' => $environment,
        ]);
    }
}
