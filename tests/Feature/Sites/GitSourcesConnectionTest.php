<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Livewire\Sites\GitSources;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteGitSource;
use App\Models\User;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Modules\WordPress\Jobs\SyncSiteGitSourceJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

function gitSourcesFixture(array $accounts): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['scaffold' => ['framework' => 'wordpress', 'layout' => 'classic']],
    ]);

    // Linked accounts are the only thing mount() asks the browser for; the repo
    // list waits for wire:init, which Livewire::test does not run.
    $browser = Mockery::mock(SourceControlRepositoryBrowser::class);
    $browser->shouldReceive('accountsForUser')->andReturn($accounts);
    app()->instance(SourceControlRepositoryBrowser::class, $browser);

    // The trait scans a pasted URL against the provider; keep that offline.
    Http::fake();

    return [$user, $server, $site];
}

test('a repo picked through a connected account needs no deploy key', function (): void {
    Queue::fake();
    [$user, $server, $site] = gitSourcesFixture([
        ['id' => 'acct-1', 'provider' => 'github', 'label' => 'acme', 'name' => 'acme'],
    ]);

    Livewire::actingAs($user)
        ->test(GitSources::class, ['server' => $server, 'site' => $site])
        ->assertSet('repo_source', 'provider')
        ->assertSet('source_control_account_id', 'acct-1')
        ->set('kind', SiteGitSource::KIND_THEME)
        ->set('slug', 'acme-theme')
        ->set('git_repository_url', 'https://github.com/acme/theme.git')
        ->set('git_branch', 'main')
        ->call('add')
        ->assertHasNoErrors();

    $source = SiteGitSource::query()->firstOrFail();

    expect($source->source_control_account_id)->toBe('acct-1')
        ->and((string) $source->connected_by_user_id)->toBe((string) $user->id)
        ->and($source->isConnected())->toBeTrue()
        // Clones with the account's access — there is nothing to install.
        ->and($source->deploy_key_public)->toBeNull();

    Queue::assertPushed(SyncSiteGitSourceJob::class);
});

test('a pasted url with no connected account still gets a deploy key', function (): void {
    Queue::fake();
    [$user, $server, $site] = gitSourcesFixture([]);

    Livewire::actingAs($user)
        ->test(GitSources::class, ['server' => $server, 'site' => $site])
        ->assertSet('repo_source', 'manual')
        ->set('kind', SiteGitSource::KIND_PLUGIN)
        ->set('slug', 'acme-plugin')
        ->set('git_repository_url', 'git@github.com:acme/plugin.git')
        ->set('git_branch', 'main')
        ->call('add')
        ->assertHasNoErrors();

    $source = SiteGitSource::query()->firstOrFail();

    expect($source->isConnected())->toBeFalse()
        ->and($source->deploy_key_public)->not->toBeNull();
});
