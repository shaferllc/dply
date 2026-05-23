<?php

declare(strict_types=1);

namespace Tests\Feature\CreateEdgeSiteTest;

use App\Actions\Edge\CreateEdgeSite;
use App\Enums\SiteType;
use App\Jobs\BuildEdgeSiteJob;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('creates edge server site deployment and dispatches build', function () {
    Queue::fake();
    [$user, $org] = scaffold();

    $site = (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'Marketing Site',
        'repo' => 'acme/marketing',
        'branch' => 'main',
        'build_command' => 'npm ci && npm run build',
        'output_dir' => 'dist',
        'spa_fallback' => true,
        'deploy_on_push' => true,
        'framework' => 'vite',
    ]);

    expect($site->type)->toBe(SiteType::Static);
    expect($site->edge_backend)->toBe('dply_edge');
    expect($site->status)->toBe(Site::STATUS_EDGE_PROVISIONING);
    expect($site->meta['runtime_profile'] ?? null)->toBe('edge_web');
    expect($site->meta['edge']['source']['repo'] ?? null)->toBe('acme/marketing');
    expect($site->meta['edge']['build']['output_dir'] ?? null)->toBe('dist');
    expect($site->server)->not->toBeNull();
    expect($site->server->hostKind())->toBe(Server::HOST_KIND_DPLY_EDGE);

    expect(EdgeDeployment::query()->where('site_id', $site->id)->count())->toBe(1);

    Queue::assertPushed(BuildEdgeSiteJob::class);
});

test('normalizes github url to owner slash repo', function () {
    Queue::fake();
    [$user, $org] = scaffold();

    $site = (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'From URL',
        'repo' => 'https://github.com/acme/site.git',
        'branch' => 'main',
    ]);

    expect($site->meta['edge']['source']['repo'] ?? null)->toBe('acme/site');
});

test('throws when repo missing', function () {
    [$user, $org] = scaffold();

    (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'No Repo',
        'repo' => '',
    ]);
})->throws(\InvalidArgumentException::class);

test('throws when runtime mode is ssr', function () {
    [$user, $org] = scaffold();

    (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'SSR',
        'repo' => 'acme/next',
        'runtime_mode' => 'ssr',
    ]);
})->throws(\RuntimeException::class);

/**
 * @return array{0: User, 1: Organization}
 */
function scaffold(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return [$user, $org];
}
