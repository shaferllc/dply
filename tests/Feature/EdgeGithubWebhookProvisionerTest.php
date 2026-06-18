<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeGithubWebhookProvisionerTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\Edge\Services\EdgeGithubWebhookProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('enable creates github hook and persists edge webhook meta', function () {
    Http::fake(function () {
        return Http::response(['id' => 4242], 201);
    });

    [$site, $account] = makeEdgeSiteWithGithubAccount();

    $provisioner = new EdgeGithubWebhookProvisioner;
    $result = $provisioner->enable($site->fresh(), $account);

    expect($result['ok'])->toBeTrue();

    $site->refresh();
    $webhook = $site->edgeMeta()['webhook'] ?? null;
    expect($webhook)->toBeArray();
    expect((string) ($webhook['hook_id'] ?? ''))->toBe('4242');
    expect($webhook['provider'] ?? null)->toBe('github');
    expect($webhook['account_id'] ?? null)->toBe((string) $account->id);
    expect($site->edgeMeta()['source']['deploy_on_push'] ?? null)->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/repos/acme/edge-app/hooks')
            && $request->method() === 'POST'
            && in_array('push', $request['events'] ?? [], true)
            && in_array('pull_request', $request['events'] ?? [], true);
    });
});

test('disable removes github hook and clears edge webhook meta', function () {
    Http::fake([
        'https://api.github.com/repos/acme/edge-app/hooks' => Http::response(['id' => 7777], 201),
        'https://api.github.com/repos/acme/edge-app/hooks/7777' => Http::response([], 204),
    ]);

    [$site, $account] = makeEdgeSiteWithGithubAccount();
    $provisioner = new EdgeGithubWebhookProvisioner;
    $provisioner->enable($site->fresh(), $account);

    $provisioner->disable($site->fresh(), $account);

    $site->refresh();
    expect(app(EdgeGithubWebhookProvisioner::class)->isConnected($site))->toBeFalse();
    expect($site->edgeMeta()['source']['deploy_on_push'] ?? null)->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/hooks/7777') && $request->method() === 'DELETE';
    });
});

/**
 * @return array{0: Site, 1: SocialAccount}
 */
function makeEdgeSiteWithGithubAccount(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Edge App',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'webhook_secret' => 'whsec_edge_test',
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/edge-app', 'branch' => 'main'],
                'routing' => ['hostname' => 'edge-app.dply.host'],
            ],
        ],
    ]);

    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-1',
        'access_token' => 'gho_edge_test',
    ]);

    return [$site->fresh(), $account];
}
