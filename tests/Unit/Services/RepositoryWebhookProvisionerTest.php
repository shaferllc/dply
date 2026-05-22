<?php

namespace Tests\Unit\Services\RepositoryWebhookProvisionerTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeploySyncGroup;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Services\Sites\SiteDeploySyncCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('enable creates github hook and persists meta', function () {
    Http::fake(function () {
        return Http::response(['id' => 9001], 201);
    });

    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'git_repository_url' => 'https://github.com/acme/demo.git',
        'webhook_secret' => 'whsec_test',
    ]);
    $site->mergeRepositoryMeta(['git_provider_kind' => 'github']);
    $site->save();

    $user = User::factory()->create();
    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => '123',
        'access_token' => 'gho_testtoken',
    ]);

    $provisioner = new RepositoryWebhookProvisioner(new SiteDeploySyncCoordinator);
    $result = $provisioner->enable($site->fresh(), $account);

    expect($result['ok'])->toBeTrue();
    $site->refresh();
    $hook = $site->repositoryMeta()['provider_hook'] ?? null;
    expect($hook)->toBeArray();
    expect((string) $hook['id'])->toBe('9001');
    expect($hook['provider'])->toBe('github');
    expect($hook['account_id'])->toBe((string) $account->id);

    Http::assertSentCount(1);
    $recorded = Http::recorded();
    expect($recorded)->not->toBeEmpty();

    /** @var Request $request */
    $request = $recorded[0][0];
    expect($request->url())->toBe('https://api.github.com/repos/acme/demo/hooks');
    $data = $request->data();
    expect($data['config']['url'] ?? null)->toBe($site->deployHookUrl());
    expect($data['config']['secret'] ?? null)->toBe('whsec_test');
});

test('follower in sync group cannot register provider hook', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $leader = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'git_repository_url' => 'https://github.com/acme/demo.git',
    ]);
    $follower = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'git_repository_url' => 'https://github.com/acme/demo.git',
    ]);

    $group = SiteDeploySyncGroup::query()->create([
        'organization_id' => $org->id,
        'name' => 'G',
        'leader_site_id' => $leader->id,
    ]);
    $group->sites()->attach($leader->id, ['id' => (string) Str::ulid(), 'sort_order' => 0]);
    $group->sites()->attach($follower->id, ['id' => (string) Str::ulid(), 'sort_order' => 1]);

    $user = User::factory()->create();
    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => '124',
        'access_token' => 'gho_testtoken',
    ]);

    $follower->mergeRepositoryMeta(['git_provider_kind' => 'github']);
    $follower->save();

    $provisioner = new RepositoryWebhookProvisioner(new SiteDeploySyncCoordinator);
    $result = $provisioner->enable($follower->fresh(), $account);

    expect($result['ok'])->toBeFalse();
});
