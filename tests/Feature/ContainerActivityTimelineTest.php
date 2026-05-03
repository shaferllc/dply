<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Edge\ContainerActivityTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerActivityTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_meta_returns_empty_timeline(): void
    {
        $site = $this->makeSite([]);

        $this->assertSame([], ContainerActivityTimeline::for($site));
    }

    public function test_collects_known_events_from_meta(): void
    {
        $site = $this->makeSite([
            'container' => [
                'provisioned_at' => '2026-05-03T10:00:00+00:00',
                'last_deploy_started_at' => '2026-05-03T11:00:00+00:00',
                'last_deployment_id' => 'dep-99',
                'last_error' => 'pull access denied',
                'last_error_at' => '2026-05-03T10:30:00+00:00',
                'last_poll_at' => '2026-05-03T11:30:00+00:00',
                'last_phase' => 'BUILDING',
                'backend' => 'digitalocean_app_platform',
            ],
        ]);

        $events = ContainerActivityTimeline::for($site);

        $kinds = array_column($events, 'kind');
        $this->assertContains('provisioned', $kinds);
        $this->assertContains('deploy', $kinds);
        $this->assertContains('error', $kinds);
        $this->assertContains('poll', $kinds);
    }

    public function test_orders_events_newest_first(): void
    {
        $site = $this->makeSite([
            'container' => [
                'provisioned_at' => '2026-05-03T10:00:00+00:00',
                'last_deploy_started_at' => '2026-05-03T12:00:00+00:00',
                'last_error_at' => '2026-05-03T11:00:00+00:00',
                'last_error' => 'oops',
            ],
        ]);

        $events = ContainerActivityTimeline::for($site);

        $this->assertSame('deploy', $events[0]['kind']);
        $this->assertSame('error', $events[1]['kind']);
        $this->assertSame('provisioned', $events[2]['kind']);
    }

    public function test_renders_domain_attach_events(): void
    {
        $site = $this->makeSite([
            'container' => [
                'domains' => [
                    'api.example.com' => ['attached_at' => '2026-05-03T13:00:00+00:00'],
                    'www.example.com' => ['attached_at' => '2026-05-03T13:30:00+00:00'],
                ],
            ],
        ]);

        $events = ContainerActivityTimeline::for($site);

        $this->assertCount(2, $events);
        $this->assertSame('domain_attached', $events[0]['kind']);
        $this->assertSame('www.example.com', $events[0]['detail']);
    }

    public function test_poll_error_classified_separately(): void
    {
        $site = $this->makeSite([
            'container' => [
                'last_poll_at' => '2026-05-03T13:00:00+00:00',
                'last_poll_error' => '503 Service Unavailable',
            ],
        ]);

        $events = ContainerActivityTimeline::for($site);

        $this->assertSame('poll_error', $events[0]['kind']);
        $this->assertSame('503 Service Unavailable', $events[0]['detail']);
    }

    public function test_dashboard_renders_recent_activity_section(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
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
            'meta' => ['container' => [
                'provisioned_at' => now()->subMinutes(5)->toIso8601String(),
                'last_deploy_started_at' => now()->subMinutes(2)->toIso8601String(),
                'backend' => 'digitalocean_app_platform',
            ]],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Recent activity')
            ->assertSee('Provisioned on backend')
            ->assertSee('Redeploy started');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function makeSite(array $meta): Site
    {
        $user = User::factory()->create();
        $server = Server::factory()->create(['user_id' => $user->id]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'meta' => $meta,
        ]);
    }
}
