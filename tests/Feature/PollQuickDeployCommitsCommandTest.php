<?php

declare(strict_types=1);

namespace Tests\Feature\PollQuickDeployCommitsCommandTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makePollSite(string $lastSha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'git_repository_url' => 'https://github.com/acme/demo.git',
        'git_branch' => 'main',
        'webhook_secret' => 'whsec_poll_test',
    ]);

    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'poll-gh-1',
        'access_token' => 'gho_poll_token',
    ]);

    $site->mergeRepositoryMeta([
        'quick_deploy_enabled' => true,
        'quick_deploy_mode' => 'poll',
        'git_provider_kind' => 'github',
        'git_source_control_account_id' => $account->id,
    ]);
    $site->save();

    if ($lastSha !== '') {
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'trigger' => SiteDeployment::TRIGGER_MANUAL,
            'status' => SiteDeployment::STATUS_SUCCESS,
            'git_sha' => $lastSha,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);
    }

    return $site->fresh();
}

test('poll command dispatches deploy when tip differs from last success', function () {
    Queue::fake();
    $site = makePollSite('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    Http::fake([
        'api.github.com/repos/acme/demo/commits*' => Http::response([
            ['sha' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'commit' => ['message' => 'new']],
        ], 200),
    ]);

    $this->artisan('dply:poll-quick-deploy-commits')->assertSuccessful();

    Queue::assertPushed(RunSiteDeploymentJob::class, function (RunSiteDeploymentJob $job) use ($site): bool {
        return $job->site->is($site) && $job->trigger === SiteDeployment::TRIGGER_POLL;
    });

    $site->refresh();
    expect($site->repositoryMeta()['poll_last_tip_sha'] ?? null)->toBe('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    expect($site->repositoryMeta()['poll_last_checked_at'] ?? null)->not->toBeNull();

    $log = $site->repositoryMeta()['poll_log'] ?? [];
    expect($log)->toBeArray()->not->toBeEmpty();
    expect($log[0]['outcome'] ?? null)->toBe('deploy_queued');
    expect($log[0]['sha'] ?? null)->toBe('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    expect($log[0]['sha_short'] ?? null)->toBe('bbbbbbb');
    expect($log[0]['message'] ?? null)->not->toBeEmpty();
    expect($log[0]['at'] ?? null)->not->toBeNull();
});

test('poll command skips when tip matches last success', function () {
    Queue::fake();
    $sha = 'cccccccccccccccccccccccccccccccccccccccc';
    $site = makePollSite($sha);

    Http::fake([
        'api.github.com/repos/acme/demo/commits*' => Http::response([
            ['sha' => $sha, 'commit' => ['message' => 'same']],
        ], 200),
    ]);

    $this->artisan('dply:poll-quick-deploy-commits')->assertSuccessful();

    Queue::assertNotPushed(RunSiteDeploymentJob::class);

    $site->refresh();
    $log = $site->repositoryMeta()['poll_log'] ?? [];
    expect($log)->toBeArray()->not->toBeEmpty();
    expect($log[0]['outcome'] ?? null)->toBe('unchanged');
    expect($log[0]['sha'] ?? null)->toBe($sha);
});

test('enablePoll sets mode and clears provider hook meta', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'git_repository_url' => 'https://github.com/acme/demo.git',
        'webhook_secret' => 'whsec',
    ]);
    $site->mergeRepositoryMeta([
        'git_provider_kind' => 'github',
        'quick_deploy_enabled' => true,
        'quick_deploy_mode' => 'webhook',
        'provider_hook' => ['id' => '1', 'provider' => 'github', 'account_id' => 'x'],
    ]);
    $site->save();

    Http::fake(); // delete hook may be attempted; ignore

    $result = app(\App\Services\Sites\RepositoryWebhookProvisioner::class)->enablePoll($site->fresh());

    expect($result['ok'])->toBeTrue();
    $site->refresh();
    expect($site->repositoryMeta()['quick_deploy_enabled'] ?? false)->toBeTrue();
    expect($site->repositoryMeta()['quick_deploy_mode'] ?? null)->toBe('poll');
    expect($site->repositoryMeta()['provider_hook'] ?? null)->toBeNull();
});
