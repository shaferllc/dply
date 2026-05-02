<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDeploymentsListPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_deployments_newest_first(): void
    {
        [$user, $server, $site] = $this->makeUserSite();
        $older = $this->seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now()->subDays(2), 'manual');
        $newer = $this->seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subHours(1), 'webhook');

        $response = $this->actingAs($user)->get(route('sites.deployments.index', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertSee('Deployments')
            ->assertSee($older->id)
            ->assertSee($newer->id);

        $body = (string) $response->getContent();
        // Newer should come before older in the rendered HTML.
        $this->assertLessThan(strpos($body, $older->id), strpos($body, $newer->id));
    }

    public function test_status_filter_narrows_to_failures(): void
    {
        [$user, $server, $site] = $this->makeUserSite();
        $success = $this->seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now(), 'manual');
        $failure = $this->seedDeploy($site, SiteDeployment::STATUS_FAILED, now(), 'manual');

        $response = $this->actingAs($user)->get(route('sites.deployments.index', [
            'server' => $server,
            'site' => $site,
        ]).'?status=failed');

        $response->assertOk()
            ->assertSee($failure->id)
            ->assertDontSee($success->id);
    }

    public function test_trigger_filter_narrows_to_one_trigger(): void
    {
        [$user, $server, $site] = $this->makeUserSite();
        $manual = $this->seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now(), 'manual');
        $webhook = $this->seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now(), 'webhook');

        $response = $this->actingAs($user)->get(route('sites.deployments.index', [
            'server' => $server,
            'site' => $site,
        ]).'?trigger=webhook');

        $response->assertOk()
            ->assertSee($webhook->id)
            ->assertDontSee($manual->id);
    }

    public function test_renders_friendly_message_when_no_deployments_match(): void
    {
        [$user, $server, $site] = $this->makeUserSite();

        $response = $this->actingAs($user)->get(route('sites.deployments.index', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertSee('No deployments match');
    }

    public function test_aborts_when_user_is_not_in_org(): void
    {
        [, $server, $site] = $this->makeUserSite();

        $stranger = User::factory()->create();
        $strangerOrg = Organization::factory()->create();
        $strangerOrg->users()->attach($stranger->id, ['role' => 'owner']);
        session(['current_organization_id' => $strangerOrg->id]);

        $response = $this->actingAs($stranger)->get(route('sites.deployments.index', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertNotFound();
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
            'meta' => ['webserver' => 'nginx'],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        return [$user, $server, $site];
    }

    private function seedDeploy(Site $site, string $status, \DateTimeInterface $startedAt, string $trigger): SiteDeployment
    {
        return SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'status' => $status,
            'trigger' => $trigger,
            'started_at' => $startedAt,
            'finished_at' => $startedAt,
        ]);
    }
}
