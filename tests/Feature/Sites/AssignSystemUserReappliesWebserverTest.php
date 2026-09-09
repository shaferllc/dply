<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Jobs\AssignSystemUserToSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * The vhost hard-codes the FPM pool socket of whichever user owned the site
 * when the config was last written. Reassigning the system user without
 * re-applying leaves the site — and every tenant hostname sharing its server
 * block — pointing at a socket that no longer serves it, i.e. a 502.
 */
function assignSystemUserFixture(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'php_fpm_user' => 'olduser',
    ]);

    return [$user, $site];
}

test('assigning a system user re-applies the webserver config', function (): void {
    Queue::fake();

    [$user, $site] = assignSystemUserFixture();

    // The SSH-backed assignment itself is covered by the service's own tests —
    // what matters here is that a successful assignment queues the re-apply.
    $service = \Mockery::mock(ServerSystemUserService::class);
    $service->shouldReceive('assignExistingUserToSite')->once();
    app()->instance(ServerSystemUserService::class, $service);

    (new AssignSystemUserToSiteJob((string) $site->id, 'newuser', (string) $user->id))
        ->handle($service);

    Queue::assertPushed(
        ApplySiteWebserverConfigJob::class,
        fn (ApplySiteWebserverConfigJob $job): bool => $job->siteId === (string) $site->id
            && $job->userId === (string) $user->id,
    );
});

test('a failed system user assignment does not re-apply the webserver config', function (): void {
    Queue::fake();

    [$user, $site] = assignSystemUserFixture();

    $service = \Mockery::mock(ServerSystemUserService::class);
    $service->shouldReceive('assignExistingUserToSite')
        ->once()
        ->andThrow(new \RuntimeException('user missing on host'));
    app()->instance(ServerSystemUserService::class, $service);

    expect(fn () => (new AssignSystemUserToSiteJob((string) $site->id, 'newuser', (string) $user->id))
        ->handle($service))->toThrow(\RuntimeException::class);

    // Rewriting the vhost for an assignment that never happened would point the
    // pool at a user the site does not own.
    Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
});
