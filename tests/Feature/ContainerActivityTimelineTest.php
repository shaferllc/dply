<?php

declare(strict_types=1);

namespace Tests\Feature\ContainerActivityTimelineTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Cloud\ContainerActivityTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('empty meta returns empty timeline', function () {
    $site = makeSite([]);

    expect(ContainerActivityTimeline::for($site))->toBe([]);
});
test('collects known events from meta', function () {
    $site = makeSite([
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
    expect($kinds)->toContain('provisioned');
    expect($kinds)->toContain('deploy');
    expect($kinds)->toContain('error');
    expect($kinds)->toContain('poll');
});
test('orders events newest first', function () {
    $site = makeSite([
        'container' => [
            'provisioned_at' => '2026-05-03T10:00:00+00:00',
            'last_deploy_started_at' => '2026-05-03T12:00:00+00:00',
            'last_error_at' => '2026-05-03T11:00:00+00:00',
            'last_error' => 'oops',
        ],
    ]);

    $events = ContainerActivityTimeline::for($site);

    expect($events[0]['kind'])->toBe('deploy');
    expect($events[1]['kind'])->toBe('error');
    expect($events[2]['kind'])->toBe('provisioned');
});
test('renders domain attach events', function () {
    $site = makeSite([
        'container' => [
            'domains' => [
                'api.example.com' => ['attached_at' => '2026-05-03T13:00:00+00:00'],
                'www.example.com' => ['attached_at' => '2026-05-03T13:30:00+00:00'],
            ],
        ],
    ]);

    $events = ContainerActivityTimeline::for($site);

    expect($events)->toHaveCount(2);
    expect($events[0]['kind'])->toBe('domain_attached');
    expect($events[0]['detail'])->toBe('www.example.com');
});
test('poll error classified separately', function () {
    $site = makeSite([
        'container' => [
            'last_poll_at' => '2026-05-03T13:00:00+00:00',
            'last_poll_error' => '503 Service Unavailable',
        ],
    ]);

    $events = ContainerActivityTimeline::for($site);

    expect($events[0]['kind'])->toBe('poll_error');
    expect($events[0]['detail'])->toBe('503 Service Unavailable');
});
test('dashboard renders recent activity section', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
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
});
/**
 * @param  array<string, mixed>  $meta
 */
function makeSite(array $meta): Site
{
    $user = User::factory()->create();
    $server = Server::factory()->create(['user_id' => $user->id]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'meta' => $meta,
    ]);
}
