<?php

declare(strict_types=1);

namespace Tests\Feature\WordPress\GitSourcesPanelTest;

use App\Jobs\ResetSiteToBlankJob;
use App\Livewire\Sites\GitSources;
use App\Livewire\Sites\Repository;
use App\Livewire\Sites\Settings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteGitSource;
use App\Models\User;
use App\Modules\WordPress\Jobs\SyncSiteGitSourceJob;
use App\Modules\WordPress\Materializers\GitSourceMaterializerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Setting a repo URL now runs the shared picker's public-repo scan; keep it offline.
beforeEach(fn () => Http::fake());

function panelUser(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function panelSite(User $user, string $framework = 'wordpress', string $layout = 'classic'): Site
{
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'meta' => ['webserver' => 'nginx', 'php_version' => '8.3'],
    ]);

    return Site::factory()->for($server)->create([
        'slug' => 'my-wp',
        'meta' => ['scaffold' => ['framework' => $framework, 'layout' => $layout]],
    ]);
}

test('adding a theme repo stores it and queues the sync', function () {
    Queue::fake();
    $user = panelUser();
    $site = panelSite($user);

    Livewire::actingAs($user)
        ->test(GitSources::class, ['server' => $site->server, 'site' => $site])
        ->set('kind', 'theme')
        ->set('slug', 'my-theme')
        ->set('git_repository_url', 'git@github.com:acme/my-theme.git')
        ->call('add')
        ->assertHasNoErrors();

    $source = $site->gitSources()->sole();

    expect($source->kind)->toBe('theme')
        ->and($source->slug)->toBe('my-theme')
        // Generated up front so the panel can show the key immediately — a
        // private repo's first sync fails until it is added upstream.
        ->and($source->deploy_key_public)->not->toBeNull();

    // SSH is never inline in a Livewire request.
    Queue::assertPushed(SyncSiteGitSourceJob::class);
});

test('the slug is guessed from the repository url but never overwrites one', function () {
    Queue::fake();
    $user = panelUser();
    $site = panelSite($user);

    $component = Livewire::actingAs($user)
        ->test(GitSources::class, ['server' => $site->server, 'site' => $site])
        ->set('git_repository_url', 'git@github.com:acme/fancy-theme.git');

    $component->assertSet('slug', 'fancy-theme');

    $component->set('slug', 'kept')
        ->set('git_repository_url', 'git@github.com:acme/other.git')
        ->assertSet('slug', 'kept');
});

test('a directory-unsafe slug is rejected', function () {
    Queue::fake();
    $user = panelUser();
    $site = panelSite($user);

    Livewire::actingAs($user)
        ->test(GitSources::class, ['server' => $site->server, 'site' => $site])
        ->set('slug', '../escape')
        ->set('git_repository_url', 'git@github.com:acme/my-theme.git')
        ->call('add')
        ->assertHasErrors('slug');

    expect($site->gitSources()->count())->toBe(0);
    Queue::assertNotPushed(SyncSiteGitSourceJob::class);
});

test('two sources cannot share a kind and slug', function () {
    Queue::fake();
    $user = panelUser();
    $site = panelSite($user);

    $site->gitSources()->create([
        'kind' => 'theme',
        'slug' => 'my-theme',
        'repository_url' => 'git@github.com:acme/my-theme.git',
        'git_branch' => 'main',
    ]);

    // WordPress cannot hold two themes in one directory.
    Livewire::actingAs($user)
        ->test(GitSources::class, ['server' => $site->server, 'site' => $site])
        ->set('kind', 'theme')
        ->set('slug', 'my-theme')
        ->set('git_repository_url', 'git@github.com:acme/other.git')
        ->call('add')
        ->assertHasErrors('slug');

    expect($site->gitSources()->count())->toBe(1);
});

test('removing queues the teardown but keeps the row until the files are gone', function () {
    Queue::fake();
    $user = panelUser();
    $site = panelSite($user);

    $source = $site->gitSources()->create([
        'kind' => 'plugin',
        'slug' => 'my-plugin',
        'repository_url' => 'git@github.com:acme/my-plugin.git',
        'git_branch' => 'main',
    ]);

    Livewire::actingAs($user)
        ->test(GitSources::class, ['server' => $site->server, 'site' => $site])
        ->call('remove', $source->id);

    // Deleting the row here would orphan a directory on the box with nothing
    // in dply pointing at it.
    expect($site->gitSources()->count())->toBe(1);

    Queue::assertPushed(SyncSiteGitSourceJob::class, fn ($job) => $job->remove === true);
});

test('the panel 404s on a non-wordpress site', function () {
    $user = panelUser();
    $site = panelSite($user, framework: 'laravel');

    Livewire::actingAs($user)
        ->test(GitSources::class, ['server' => $site->server, 'site' => $site])
        ->assertStatus(404);
});

test('a user with no access to the site cannot add a theme repo', function () {
    Queue::fake();

    $owner = panelUser();
    $site = panelSite($owner);

    // SitePolicy::update delegates to ServerPolicy::update, so the real
    // boundary is server access, not the org deployer role — a deployer who
    // can already administer the server may change what its sites serve, but
    // an outsider may not. Adding a theme repo mints no infrastructure.
    $outsider = panelUser();

    Livewire::actingAs($outsider)
        ->test(GitSources::class, ['server' => $site->server, 'site' => $site])
        ->assertForbidden();

    expect($site->gitSources()->count())->toBe(0);
    Queue::assertNotPushed(SyncSiteGitSourceJob::class);
});

test('the sync job refuses a non-wordpress site instead of running composer at it', function () {
    $user = panelUser();
    $site = panelSite($user, framework: 'laravel');

    $source = $site->gitSources()->create([
        'kind' => 'theme',
        'slug' => 'my-theme',
        'repository_url' => 'git@github.com:acme/my-theme.git',
        'git_branch' => 'main',
    ]);

    (new SyncSiteGitSourceJob($source->id))->handle(app(GitSourceMaterializerFactory::class));

    $source->refresh();
    expect($source->status)->toBe(SiteGitSource::STATUS_ERROR)
        ->and($source->last_error)->toContain('WordPress');
});

test('the sync job is a no-op when the source was already deleted', function () {
    $factory = app(GitSourceMaterializerFactory::class);

    // Dispatched, then removed before the worker picked it up.
    expect(fn () => (new SyncSiteGitSourceJob('01JQZZZZZZZZZZZZZZZZZZZZZZ'))->handle($factory))
        ->not->toThrow(\Throwable::class);
});

test('disconnect and start over clears the theme and plugin repos too', function () {
    Queue::fake();
    $user = panelUser();
    $site = panelSite($user);

    $site->gitSources()->create([
        'kind' => 'theme',
        'slug' => 'my-theme',
        'repository_url' => 'git@github.com:acme/my-theme.git',
        'git_branch' => 'main',
    ]);

    Livewire::actingAs($user)
        ->test(Repository::class, ['server' => $site->server, 'site' => $site])
        ->call('disconnectAndStartOver');

    // Without this, a reset site keeps rows pointing at directories
    // ResetSiteToBlankJob has just wiped, and the picker re-opens with stale
    // themes attached to an app that no longer exists.
    expect($site->gitSources()->count())->toBe(0);
    expect($site->fresh()->canRechooseApp())->toBeTrue();
});

test('a repo-less wordpress site can still be reset and re-choose its app', function () {
    Queue::fake();
    $user = panelUser();
    $site = panelSite($user);

    // Classic WordPress is manage-in-place: no git_repository_url. The reset
    // affordance used to be hidden for exactly these sites, and the picker was
    // already closed, so they had no way back to the app chooser.
    expect(trim((string) $site->git_repository_url))->toBe('')
        ->and($site->canRechooseApp())->toBeFalse();

    Livewire::actingAs($user)
        ->test(Repository::class, ['server' => $site->server, 'site' => $site])
        ->call('disconnectAndStartOver');

    expect($site->fresh()->canRechooseApp())->toBeTrue();
});

test('a healthy app can be reset on demand from the danger section', function () {
    Queue::fake();
    $user = panelUser();
    $site = panelSite($user);

    $site->gitSources()->create([
        'kind' => 'theme',
        'slug' => 'my-theme',
        'repository_url' => 'git@github.com:acme/my-theme.git',
        'git_branch' => 'main',
    ]);

    // Nothing is wrong with this site — resetting is just something you may
    // want to do. Previously only the Repository tab's danger zone (repo-gated)
    // or the scaffold journey (install-time only) offered it.
    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $site->server, 'site' => $site, 'section' => 'danger'])
        ->call('resetSiteApp');

    $site->refresh();

    expect($site->status)->toBe(Site::STATUS_AWAITING_APP)
        ->and($site->canRechooseApp())->toBeTrue()
        ->and(data_get($site->meta, 'scaffold'))->toBeNull()
        ->and($site->gitSources()->count())->toBe(0);

    Queue::assertPushed(ResetSiteToBlankJob::class);
});

test('a site with no app has nothing to reset', function () {
    $user = panelUser();

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'meta' => ['webserver' => 'nginx', 'php_version' => '8.3'],
    ]);
    $bare = Site::factory()->for($server)->create(['slug' => 'bare', 'meta' => []]);
    $bare->forceFill(['git_repository_url' => ''])->save();

    $component = Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $bare->fresh(), 'section' => 'danger']);

    // Gates the Danger-section block. Covers both shapes an app can take: a
    // connected repo, or a manage-in-place install with no repo at all.
    expect($component->instance()->siteHasResettableApp())->toBeFalse();
});
