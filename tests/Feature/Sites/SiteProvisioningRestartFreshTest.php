<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Jobs\ProvisionSiteJob;
use App\Livewire\Sites\Show;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SitePreviewDomain;
use App\Models\User;
use App\Services\Sites\SiteProvisioningRestarter;
use App\Services\Sites\SiteWebserverConfigApplier;
use App\Services\Sites\TestingHostnameProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

test('restart fresh opens confirm modal with wipe copy', function (): void {
    [$user, $server, $site] = provisioningSite();

    Livewire::actingAs($user)
        ->test(Show::class, ['server' => $server, 'site' => $site])
        ->call('openRestartProvisioningFreshModal')
        ->assertSet('confirmActionModalTitle', __('Restart provisioning from scratch?'))
        ->assertSet('confirmActionModalConfirmLabel', __('Restart fresh'));
});

test('restart fresh delegates cleanup and requeues provisioning', function (): void {
    Queue::fake();

    [$user, $server, $site] = provisioningSite();

    $restarter = Mockery::mock(SiteProvisioningRestarter::class);
    $restarter->shouldReceive('restart')
        ->once()
        ->withArgs(fn (Site $passed): bool => (string) $passed->id === (string) $site->id);
    app()->instance(SiteProvisioningRestarter::class, $restarter);

    Livewire::actingAs($user)
        ->test(Show::class, ['server' => $server, 'site' => $site])
        ->call('restartProvisioningFresh')
        ->assertHasNoErrors();
});

test('site provisioning restarter resets local state and queues provision job', function (): void {
    Queue::fake();

    [$user, $server, $site] = provisioningSite();

    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'testing-abc123.ondply.io',
        'is_primary' => true,
    ]);

    SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_PREVIEW,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'domains_json' => ['testing-abc123.ondply.io'],
        'status' => SiteCertificate::STATUS_FAILED,
    ]);

    $site->forceFill([
        'meta' => array_merge(is_array($site->meta) ? $site->meta : [], [
            'edge_backend_last_output' => 'old',
            'testing_hostname' => ['status' => 'ready', 'hostname' => 'testing-abc123.ondply.io'],
            'provisioning' => ['state' => 'failed', 'error' => 'boom'],
        ]),
        'ssl_status' => Site::SSL_FAILED,
    ])->save();

    $applier = Mockery::mock(SiteWebserverConfigApplier::class);
    $applier->shouldReceive('remove')->once()->andReturn('removed');
    app()->instance(SiteWebserverConfigApplier::class, $applier);

    $testing = Mockery::mock(TestingHostnameProvisioner::class);
    $testing->shouldReceive('delete')->once();
    app()->instance(TestingHostnameProvisioner::class, $testing);

    app(SiteProvisioningRestarter::class)->restart($site->fresh(['server', 'certificates', 'previewDomains']));

    $site->refresh();

    expect($site->status)->toBe(Site::STATUS_PENDING)
        ->and($site->ssl_status)->toBe(Site::SSL_NONE)
        ->and($site->provisioningState())->toBe('queued')
        ->and($site->meta['edge_backend_last_output'] ?? null)->toBeNull()
        ->and($site->meta['testing_hostname'] ?? null)->toBeNull()
        ->and(SiteCertificate::query()->where('site_id', $site->id)->count())->toBe(0);

    Queue::assertPushed(ProvisionSiteJob::class, fn (ProvisionSiteJob $job): bool => $job->siteId === $site->id);
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function provisioningSite(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'status' => Site::STATUS_PENDING,
        'meta' => [
            'provisioning' => [
                'state' => 'waiting_for_http',
                'error' => null,
            ],
        ],
    ]);

    return [$user, $server, $site];
}
