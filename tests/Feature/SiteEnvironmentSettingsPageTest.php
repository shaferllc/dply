<?php

declare(strict_types=1);

namespace Tests\Feature\SiteEnvironmentSettingsPageTest;
use App\Jobs\PushSiteEnvJob;
use App\Jobs\SyncEnvFromServerJob;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('add env var writes cache and dispatches push job', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->set('new_env_key', 'API_KEY')
        ->set('new_env_value', 'super-secret')
        ->call('addEnvVar')
        ->assertSet('new_env_key', '')
        ->assertSet('new_env_value', '');

    $site->refresh();
    expect(parsed($site))->toBe(['API_KEY' => 'super-secret']);
    expect($site->env_cache_origin)->toBe('local-edit');
    Queue::assertPushed(PushSiteEnvJob::class, fn ($job) => $job->siteId === $site->id);
});
test('add env var skips push for unsupported runtime', function () {
    Queue::fake();
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->set('new_env_key', 'API_KEY')
        ->set('new_env_value', 'x')
        ->call('addEnvVar');

    expect(parsed($site->fresh()))->toBe(['API_KEY' => 'x']);
    Queue::assertNotPushed(PushSiteEnvJob::class);
});
test('add env var validates key format', function () {
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->set('new_env_key', 'lower-case')
        ->set('new_env_value', 'x')
        ->call('addEnvVar')
        ->assertHasErrors(['new_env_key']);

    expect(parsed($site->fresh()))->toBe([]);
});
test('bulk import merges and overwrites then auto pushes', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => "KEEP=k\nOVERRIDE=old",
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->set('bulk_env_input', "OVERRIDE=new\nFRESH=val\n")
        ->call('bulkImportEnvVars')
        ->assertSet('bulk_env_input', '');

    expect(parsed($site->fresh()))->toBe([
        'FRESH' => 'val',
        'KEEP' => 'k',
        'OVERRIDE' => 'new',
    ]);
    Queue::assertPushed(PushSiteEnvJob::class);
});
test('bulk import surfaces parser errors', function () {
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->set('bulk_env_input', "MALFORMED_LINE\n")
        ->call('bulkImportEnvVars')
        ->assertHasErrors(['bulk_env_input']);

    expect(parsed($site->fresh()))->toBe([]);
});
test('edit env var pulls value into form', function () {
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => 'DB_PASSWORD=hunter2',
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('editEnvVar', 'DB_PASSWORD')
        ->assertSet('editing_env_key', 'DB_PASSWORD')
        ->assertSet('editing_env_value', 'hunter2');
});
test('add env var with comment round trips', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->set('new_env_key', 'STRIPE_KEY')
        ->set('new_env_value', 'sk_live_abc')
        ->set('new_env_comment', 'rotate quarterly')
        ->call('addEnvVar')
        ->assertSet('new_env_comment', '');

    $blob = (string) $site->fresh()->env_file_content;
    $this->assertStringContainsString("# rotate quarterly\nSTRIPE_KEY=sk_live_abc", $blob);
});
test('bulk import preserves comments above keys', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite();

    $paste = "# Database settings\nDB_PASSWORD=hunter2\n\n# free-floating, no key after — dropped\n\nAPP_NAME=demo\n";

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->set('bulk_env_input', $paste)
        ->call('bulkImportEnvVars');

    $blob = (string) $site->fresh()->env_file_content;
    $this->assertStringContainsString("# Database settings\nDB_PASSWORD=hunter2", $blob);
    $this->assertStringContainsString('APP_NAME=demo', $blob);

    // Free-floating comment was dropped (it was followed by a blank line,
    // breaking the comment-to-key association).
    $this->assertStringNotContainsString('free-floating', $blob);
});
test('edit env var pulls comment into form', function () {
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => "# rotate quarterly\nSTRIPE_KEY=sk_live\n",
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('editEnvVar', 'STRIPE_KEY')
        ->assertSet('editing_env_comment', 'rotate quarterly');
});
test('save edited env var writes back and auto pushes', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => 'APP_NAME=old',
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('editEnvVar', 'APP_NAME')
        ->set('editing_env_value', 'new')
        ->call('saveEditedEnvVar')
        ->assertSet('editing_env_key', null);

    expect(parsed($site->fresh()))->toBe(['APP_NAME' => 'new']);
    Queue::assertPushed(PushSiteEnvJob::class);
});
test('remove env var deletes key and auto pushes', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => "A=1\nB=2",
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('removeEnvVar', 'A');

    expect(parsed($site->fresh()))->toBe(['B' => '2']);
    Queue::assertPushed(PushSiteEnvJob::class);
});
test('confirm remove env var opens modal without deleting', function () {
    // The trash button now goes through a confirm step. Calling
    // confirmRemoveEnvVar must NOT mutate the cache or dispatch a push;
    // it just flips the modal state. The actual delete fires when the
    // operator clicks Confirm in the modal (which dispatches removeEnvVar).
    Queue::fake();
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => "A=1\nB=2",
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('confirmRemoveEnvVar', 'A')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'removeEnvVar')
        ->assertSet('confirmActionModalArguments', ['A'])
        ->assertSet('confirmActionModalDestructive', true);

    // Cache untouched, no push dispatched yet.
    expect(parsed($site->fresh()))->toBe(['A' => '1', 'B' => '2']);
    Queue::assertNotPushed(PushSiteEnvJob::class);
});
test('confirm modal completion actually deletes', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => "A=1\nB=2",
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('confirmRemoveEnvVar', 'A')
        ->call('confirmActionModal');

    expect(parsed($site->fresh()))->toBe(['B' => '2']);
    Queue::assertPushed(PushSiteEnvJob::class);
});
test('manual push method still dispatches job', function () {
    // The Push button was removed in favor of auto-push, but the
    // pushEnvToServer Livewire method stays callable as the manual
    // recovery path (and is what CLI / future "Retry" affordances
    // route through).
    Queue::fake();
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => 'A=1',
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('pushEnvToServer');

    Queue::assertPushed(PushSiteEnvJob::class, fn ($job) => $job->siteId === $site->id);
});
test('auto sync first visit dispatches when cache empty', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite();

    // Simulate the wire:init fire-after-render call.
    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('autoSyncIfFirstVisit');

    Queue::assertPushed(SyncEnvFromServerJob::class, fn ($job) => $job->siteId === $site->id);
});
test('auto sync first visit no op when cache has content', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => 'A=1',
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('autoSyncIfFirstVisit');

    Queue::assertNotPushed(SyncEnvFromServerJob::class);
});
test('auto sync first visit no op when origin already set', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite();

    // Cache might be empty BUT origin='local-edit' means the operator
    // has explicitly cleared it; we mustn't replace that with server data.
    $site->forceFill(['env_cache_origin' => 'local-edit'])->save();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('autoSyncIfFirstVisit');

    Queue::assertNotPushed(SyncEnvFromServerJob::class);
});
test('auto sync first visit no op for unsupported runtime', function () {
    Queue::fake();
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('autoSyncIfFirstVisit');

    Queue::assertNotPushed(SyncEnvFromServerJob::class);
});
test('manual push no op for unsupported runtime', function () {
    Queue::fake();
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('pushEnvToServer');

    Queue::assertNotPushed(PushSiteEnvJob::class);
});
test('toggle reveal env var flips state', function () {
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => 'A=1',
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('toggleRevealEnvVar', 'A')
        ->assertSet('revealed_env_keys', ['A'])
        ->call('toggleRevealEnvVar', 'A')
        ->assertSet('revealed_env_keys', []);
});
test('sync env from server dispatches job', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('syncEnvFromServer');

    Queue::assertPushed(SyncEnvFromServerJob::class, fn ($job) => $job->siteId === $site->id);
});
test('save env file path stores absolute override', function () {
    // Path saves now auto-push, so the job must be faked or a real SSH
    // would be attempted by the dispatched job.
    Queue::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->set('env_file_path_override', '/etc/dply/jobs.env')
        ->call('saveEnvFilePath')
        ->assertHasNoErrors();

    expect($site->fresh()->env_file_path)->toBe('/etc/dply/jobs.env');
    expect($site->fresh()->effectiveEnvFilePath())->toBe('/etc/dply/jobs.env');
    Queue::assertPushed(PushSiteEnvJob::class);
});
test('save env file path rejects relative path', function () {
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->set('env_file_path_override', 'etc/dply/jobs.env')
        ->call('saveEnvFilePath')
        ->assertHasErrors(['env_file_path_override']);

    expect($site->fresh()->env_file_path)->toBeNull();
});
test('relocate env outside docroot sets path and dispatches push', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('relocateEnvOutsideDocroot');

    $expected = '/etc/dply/'.$site->slug.'.env';
    expect($site->fresh()->env_file_path)->toBe($expected);
    Queue::assertPushed(PushSiteEnvJob::class, fn ($job) => $job->siteId === $site->id);
});
test('save env file path blank clears override', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite();
    $site->forceFill(['env_file_path' => '/etc/dply/old.env'])->save();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->set('env_file_path_override', '')
        ->call('saveEnvFilePath');

    expect($site->fresh()->env_file_path)->toBeNull();
});
test('sync env from server no op for unsupported runtime', function () {
    Queue::fake();
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    // App-Platform host has no server-side .env, so sync should be refused.
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->call('syncEnvFromServer');

    Queue::assertNotPushed(SyncEnvFromServerJob::class);
});
/**
 * @param  array<string, mixed>  $siteAttrs
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeUserSite(array $siteAttrs = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => 'fake-key',
    ]);
    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ], $siteAttrs));

    return [$user, $server, $site];
}
/**
 * @return array<string, string>
 */
function parsed(Site $site): array
{
    $vars = app(DotEnvFileParser::class)->parse((string) ($site->env_file_content ?? ''))['variables'];
    ksort($vars);

    return $vars;
}
