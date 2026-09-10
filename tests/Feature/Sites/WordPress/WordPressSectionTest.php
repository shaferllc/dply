<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\WordPress\WordPressSectionTest;

use App\Enums\SiteType;
use App\Jobs\SiteResetPermissionsJob;
use App\Livewire\Sites\WordPress\WordPressSection;
use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Modules\RemoteCli\Jobs\RunRemoteCliInBackgroundJob;
use App\Modules\RemoteCli\Services\Kind;
use App\Modules\RemoteCli\Services\RiskLevel;
use App\Modules\RemoteCli\Services\SiteAuditWriter;
use App\Modules\Snapshots\Jobs\TakeSiteSnapshotJob;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\WordPress\Jobs\SwitchWordPressCronHandlerJob;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\WordPress\Advisories\Advisory;
use App\Services\WordPress\Advisories\AdvisoryProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
function makeWpSite(string $userRole = 'admin'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $userRole]);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Php,
        'document_root' => '/home/dply/wp/current',
        'meta' => ['scaffold' => ['framework' => 'wordpress']],
    ]);

    return [$user, $site];
}

/** @return array{0: User, 1: Site} */
function makeWpProjectViewerSite(): array
{
    $owner = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);

    $viewer = User::factory()->create();
    $org->users()->attach($viewer->id, ['role' => 'member']);

    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->create([
        'user_id' => $viewer->id,
        'role' => WorkspaceMember::ROLE_VIEWER,
    ]);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
        'type' => SiteType::Php,
        'document_root' => '/home/dply/wp/current',
        'meta' => ['scaffold' => ['framework' => 'wordpress']],
    ]);

    return [$viewer, $site];
}
test('section renders friendly placeholder for non wordpress site', function () {
    // Same degradation pattern as the Laravel section: we don't
    // 404 when the site isn't detected as WordPress because operators
    // navigate around section URLs without confirming detection
    // first. Show a friendly explanation instead.
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Php,
        'meta' => [],
    ]);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->assertSee('This section appears when the site is detected as a WordPress install')
        ->assertDontSee('wp-cli Console');
});
test('renders default console tab', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->assertSet('tab', 'console')
        ->assertSee('wp-cli Console');
});
test('run console command creates remotecli run', function () {
    [$user, $site] = makeWpSite();

    // 'plugin list' is INSTANT — runs sync via the executor.
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->once()
        ->andReturn(new ProcessOutput('akismet,active,1.0.0', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('consoleCommand', 'plugin list')
        ->set('consoleArgs', '--format=csv')
        ->call('runConsoleCommand');

    $run = RemoteCliRun::query()->where('site_id', $site->id)->sole();
    expect($run->kind)->toBe(Kind::Wp);
    expect($run->command)->toBe('plugin list');
    expect($run->args)->toBe(['--format=csv']);
    expect($run->status)->toBe('completed');
});
test('console blocks destructive for member with friendly error', function () {
    [$user, $site] = makeWpSite(userRole: 'member');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('consoleCommand', 'db drop')
        ->call('runConsoleCommand')
        ->assertHasErrors('consoleCommand');

    expect(RemoteCliRun::query()->count())->toBe(0);
});
test('console rejects empty command', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('consoleCommand', '')
        ->call('runConsoleCommand')
        ->assertHasErrors('consoleCommand');

    expect(RemoteCliRun::query()->count())->toBe(0);
});
test('cron tab shows default handler', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'cron')
        ->assertSee('wp-cron via HTTP (default)')
        ->assertSee('Switch to system cron');
});
test('load plugins runs wp plugin list and decorates with advisories', function () {
    [$user, $site] = makeWpSite();

    // wp plugin list is on the INSTANT allowlist — runs sync.
    // RemoteCli::executeSync collects stdout via the per-chunk
    // callback, so the mock must invoke it to populate the result.
    $jsonOutput = json_encode([
        ['name' => 'akismet', 'status' => 'active', 'version' => '5.3', 'update' => 'available'],
        ['name' => 'hello',    'status' => 'inactive', 'version' => '1.7', 'update' => 'none'],
    ]);
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->once()
        ->withArgs(function ($s, $name, $bash, callable $cb) use ($jsonOutput) {
            $cb('out', $jsonOutput);

            return true;
        })
        ->andReturn(new ProcessOutput($jsonOutput, 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    // Advisory provider returns one CVE for akismet 5.3 only.
    $advisories = Mockery::mock(AdvisoryProvider::class);
    $advisories->shouldReceive('forPlugin')
        ->withArgs(fn (string $slug, string $v) => $slug === 'akismet' && $v === '5.3')
        ->andReturn([new Advisory('wfi-1', 'XSS in akismet', 'high', 'CVE-2024-X', '5.4', 'https://example.com/cve')]);
    $advisories->shouldReceive('forPlugin')
        ->withArgs(fn (string $slug) => $slug === 'hello')
        ->andReturn([]);
    app()->instance(AdvisoryProvider::class, $advisories);

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'plugins')
        ->call('loadPlugins')
        ->assertSet('pluginsLoaded', true);

    $plugins = $component->get('plugins');
    expect($plugins)->toHaveCount(2);
    expect($plugins[0]['name'])->toBe('akismet');
    expect($plugins[0]['update'])->toBe('available');
    expect($plugins[0]['advisories'])->toHaveCount(1);
    expect($plugins[0]['advisories'][0]['cve'])->toBe('CVE-2024-X');
    expect($plugins[1]['advisories'])->toHaveCount(0);
});
test('load plugins handles empty install gracefully', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->withArgs(function ($s, $name, $bash, callable $cb) {
            $cb('out', '[]');

            return true;
        })
        ->andReturn(new ProcessOutput('[]', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    $advisories = Mockery::mock(AdvisoryProvider::class);
    $advisories->shouldNotReceive('forPlugin');
    app()->instance(AdvisoryProvider::class, $advisories);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'plugins')
        ->call('loadPlugins')
        ->assertSet('plugins', [])
        ->assertSet('pluginsLoaded', true);
});
test('update all plugins dispatches async command', function () {
    [$user, $site] = makeWpSite();

    // 'plugin update' is mutating-recoverable but NOT instant — async dispatch.
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'plugins')
        ->call('updateAllPlugins');

    $run = RemoteCliRun::query()->where('command', 'plugin update')->sole();
    expect($run->args)->toBe(['--all']);
});
test('database tab renders empty state when no snapshots', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'database')
        ->assertSee('Database snapshots')
        ->assertSee('No snapshots yet');
});
test('take snapshot blocks member role with inline error', function () {
    [$user, $site] = makeWpSite(userRole: 'member');

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'database')
        ->call('takeSnapshot')
        ->assertHasErrors('snapshots');

    expect(Snapshot::query()->count())->toBe(0);
});
test('database tab lists existing snapshots', function () {
    [$user, $site] = makeWpSite();
    Snapshot::factory()->create([
        'site_id' => $site->id,
        'reason' => 'manual',
        'bytes' => 2048,
    ]);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'database')
        ->assertSee('snap-')
        ->assertSee('manual');
});
test('hardening tab renders existing opinions as toggles', function () {
    [$user, $site] = makeWpSite();
    $site->meta = ['scaffold' => [
        'framework' => 'wordpress',
        'hardening' => [
            ['key' => 'disallow_file_edit', 'enabled' => true],
            ['key' => 'force_ssl_admin', 'enabled' => false],
        ],
    ]];
    $site->save();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'hardening')
        ->assertSee('Hardening defaults')
        ->assertSee('DISALLOW_FILE_EDIT')
        ->assertSee('FORCE_SSL_ADMIN')
        ->assertSee('DISABLE_WP_CRON');
});
test('toggle hardening runs wp config set and updates meta', function () {
    [$user, $site] = makeWpSite();
    $site->meta = ['scaffold' => [
        'framework' => 'wordpress',
        'hardening' => [['key' => 'force_ssl_admin', 'enabled' => false]],
    ]];
    $site->save();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('Success', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'hardening')
        ->call('toggleHardening', 'force_ssl_admin');

    $site->refresh();
    $opinion = collect($site->meta['scaffold']['hardening'])->firstWhere('key', 'force_ssl_admin');
    expect($opinion['enabled'])->toBeTrue();

    $run = RemoteCliRun::query()->where('command', 'config set')->sole();
    expect($run->args)->toBe(['FORCE_SSL_ADMIN', 'true', '--raw', '--type=constant']);
});
test('toggle hardening off runs wp config delete', function () {
    [$user, $site] = makeWpSite();
    $site->meta = ['scaffold' => [
        'framework' => 'wordpress',
        'hardening' => [['key' => 'disallow_file_edit', 'enabled' => true]],
    ]];
    $site->save();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('Success', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'hardening')
        ->call('toggleHardening', 'disallow_file_edit');

    $site->refresh();
    $opinion = collect($site->meta['scaffold']['hardening'])->firstWhere('key', 'disallow_file_edit');
    expect($opinion['enabled'])->toBeFalse();

    $run = RemoteCliRun::query()->where('command', 'config delete')->sole();
    expect($run->args)->toBe(['DISALLOW_FILE_EDIT', '--type=constant']);
});
test('toggle hardening blocks member role', function () {
    [$user, $site] = makeWpSite(userRole: 'member');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'hardening')
        ->call('toggleHardening', 'disable_wp_cron')
        ->assertHasErrors('hardening');

    expect(RemoteCliRun::query()->count())->toBe(0);
});
test('toggle hardening rejects unknown opinion', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'hardening')
        ->call('toggleHardening', 'totally-fake-opinion')
        ->assertHasErrors('hardening');
});
test('load themes runs wp theme list and populates rows', function () {
    [$user, $site] = makeWpSite();

    $json = json_encode([
        ['name' => 'twentytwentyfour', 'status' => 'active', 'version' => '1.2', 'update' => 'available'],
        ['name' => 'twentytwentythree', 'status' => 'inactive', 'version' => '1.4', 'update' => 'none'],
    ]);
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->once()
        ->withArgs(function ($s, $name, $bash, callable $cb) use ($json) {
            $cb('out', $json);

            return true;
        })
        ->andReturn(new ProcessOutput($json, 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'themes')
        ->call('loadThemes')
        ->assertSet('themesLoaded', true);

    $themes = $component->get('themes');
    expect($themes)->toHaveCount(2);
    expect($themes[0]['name'])->toBe('twentytwentyfour');
    expect($themes[0]['status'])->toBe('active');
    expect($themes[0]['update'])->toBe('available');
});
test('load users runs wp user list and populates rows', function () {
    [$user, $site] = makeWpSite();

    $json = json_encode([
        ['ID' => 1, 'user_login' => 'admin', 'display_name' => 'Site Admin', 'user_email' => 'admin@example.com', 'roles' => 'administrator'],
    ]);
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->once()
        ->withArgs(function ($s, $name, $bash, callable $cb) use ($json) {
            $cb('out', $json);

            return true;
        })
        ->andReturn(new ProcessOutput($json, 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'users')
        ->call('loadUsers')
        ->assertSet('usersLoaded', true);

    $users = $component->get('users');
    expect($users)->toHaveCount(1);
    expect($users[0]['login'])->toBe('admin');
    expect($users[0]['roles'])->toBe('administrator');
});
test('load core reports installed version and update availability', function () {
    fakeCoreReleases();
    [$user, $site] = makeWpSite();

    // Two sync reads: `core version` then `core check-update --format=json`.
    $checkUpdate = json_encode([
        ['version' => '6.6', 'update_type' => 'major'],
    ]);
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->twice()
        ->andReturnUsing(function ($s, $name, $bash, callable $cb) use ($checkUpdate) {
            if (str_contains($bash, 'check-update')) {
                $cb('out', $checkUpdate);

                return new ProcessOutput($checkUpdate, 0, false);
            }

            $cb('out', '6.5.2');

            return new ProcessOutput('6.5.2', 0, false);
        });
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'core')
        ->call('loadCore')
        ->assertSet('coreLoaded', true);

    $core = $component->get('core');
    expect($core['version'])->toBe('6.5.2');
    expect($core['update_available'])->toBeTrue();
    expect($core['latest'])->toBe('6.6');
});
test('activate plugin dispatches recoverable async command', function () {
    [$user, $site] = makeWpSite();

    // 'plugin activate' is mutating-recoverable but not instant — async.
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'plugins')
        ->call('activatePlugin', 'akismet');

    $run = RemoteCliRun::query()->where('command', 'plugin activate')->sole();
    expect($run->args)->toBe(['akismet']);
});
test('plugin action rejects an invalid slug without dispatching', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'plugins')
        ->call('updatePlugin', 'evil; rm -rf /');

    expect(RemoteCliRun::query()->count())->toBe(0);
});
test('update core dispatches recoverable async command', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'core')
        ->call('updateCore');

    $run = RemoteCliRun::query()->where('command', 'core update')->sole();
    expect($run->args)->toBe([]);
});
test('install plugin dispatches plugin install with activate flag', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'plugins')
        ->set('pluginInstallSlug', 'wordpress-seo')
        ->call('installPlugin')
        ->assertSet('pluginInstallSlug', '');

    $run = RemoteCliRun::query()->where('command', 'plugin install')->sole();
    expect($run->args)->toBe(['wordpress-seo', '--activate']);
});
test('install plugin rejects invalid slug without dispatching', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'plugins')
        ->set('pluginInstallSlug', 'bad slug; rm -rf /')
        ->call('installPlugin')
        ->assertHasErrors('plugins');

    expect(RemoteCliRun::query()->count())->toBe(0);
});
test('confirm delete plugin opens modal then deletes on confirm', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'plugins')
        ->call('confirmDeletePlugin', 'akismet')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'deletePlugin');

    // Nothing runs until the modal is confirmed.
    expect(RemoteCliRun::query()->count())->toBe(0);

    $component->call('confirmActionModal')
        ->assertSet('showConfirmActionModal', false);

    $run = RemoteCliRun::query()->where('command', 'plugin delete')->sole();
    expect($run->args)->toBe(['akismet']);
});
test('delete plugin is blocked for member role by the destructive gate', function () {
    [$user, $site] = makeWpSite(userRole: 'member');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'plugins')
        ->call('deletePlugin', 'akismet');

    expect(RemoteCliRun::query()->count())->toBe(0);
});
test('install theme dispatches theme install', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'themes')
        ->set('themeInstallSlug', 'twentytwentyfive')
        ->call('installTheme')
        ->assertSet('themeInstallSlug', '');

    $run = RemoteCliRun::query()->where('command', 'theme install')->sole();
    expect($run->args)->toBe(['twentytwentyfive']);
});
test('confirm delete theme deletes on confirm', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'themes')
        ->call('confirmDeleteTheme', 'twentytwentythree')
        ->assertSet('confirmActionModalMethod', 'deleteTheme')
        ->call('confirmActionModal');

    $run = RemoteCliRun::query()->where('command', 'theme delete')->sole();
    expect($run->args)->toBe(['twentytwentythree']);
});
test('list action buttons hidden for member role', function () {
    [$user, $site] = makeWpSite(userRole: 'member');

    // Org members on an unrestricted site still have SitePolicy::update
    // (no project RBAC), so recoverable actions stay available. Only
    // destructive stays admin-gated.
    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->assertViewHas('canMutate', true)
        ->assertViewHas('canDestroy', false);
});
test('project viewer cannot run mutating wp-cli from the console', function (string $command, string $args) {
    [$viewer, $site] = makeWpProjectViewerSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($viewer)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('consoleCommand', $command)
        ->set('consoleArgs', $args)
        ->call('runConsoleCommand')
        ->assertForbidden();

    expect(RemoteCliRun::query()->count())->toBe(0);
})->with([
    ['user create', 'alice alice@example.com --role=administrator'],
    ['user set-role', 'alice administrator'],
    ['plugin install', 'evil-plugin --activate'],
    ['db query', 'SELECT user_pass FROM wp_users'],
]);
test('project viewer can run inventory reads but config values stay masked', function () {
    [$viewer, $site] = makeWpProjectViewerSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->once()
        ->withArgs(function ($s, $name, $bash, callable $cb) {
            expect($bash)->toContain('config get');
            $cb('out', 'super-secret-db-password');

            return true;
        })
        ->andReturn(new ProcessOutput('super-secret-db-password', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($viewer)
        ->test(WordPressSection::class, ['site' => $site])
        ->assertViewHas('canMutate', false)
        ->set('consoleCommand', 'config get')
        ->set('consoleArgs', 'DB_PASSWORD')
        ->call('runConsoleCommand')
        ->assertSuccessful()
        ->assertDontSee('super-secret-db-password')
        ->assertSee('********');

    $run = RemoteCliRun::query()->where('site_id', $site->id)->sole();
    expect($run->command)->toBe('config get');
    expect($run->stdout)->toBe('********');
    expect($run->stdout)->not->toContain('super-secret-db-password');
});
test('project viewer cannot point the console at another run via latestRunId', function () {
    [$viewer, $site] = makeWpProjectViewerSite();

    expect(fn () => Livewire::actingAs($viewer)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('latestRunId', 999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
test('project viewer cannot install a plugin from the plugins tab', function () {
    [$viewer, $site] = makeWpProjectViewerSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($viewer)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'plugins')
        ->set('pluginInstallSlug', 'wordpress-seo')
        ->call('installPlugin')
        ->assertForbidden();

    expect(RemoteCliRun::query()->count())->toBe(0);
});
test('switch to system cron records handler on meta', function () {
    [$user, $site] = makeWpSite();

    // 'config set' is non-instant — async dispatch path; we don't
    // care about the actual SSH call here, just the meta update.
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'cron')
        ->call('switchToSystemCron')
        ->assertHasNoErrors();

    // The switch is queued: the job installs the crontab entry before it
    // disables wp-cron (see SwitchWordPressCronHandlerJobTest).
    Queue::assertPushed(SwitchWordPressCronHandlerJob::class, fn ($job) => $job->to === 'system');
    $site->refresh();
    expect($site->meta['wp_cron']['switching_to'])->toBe('system');
});

test('installing a theme from the directory pins the version and does not activate unless asked', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('themeDetail', [
            'slug' => 'twentytwentyfive', 'versions' => ['1.5', '1.4'],
            'compatibility' => ['blockers' => [], 'warnings' => []],
        ])
        ->set('themeDetailVersion', '1.4')
        ->call('installThemeFromDirectory')
        ->assertSet('themeDetail', null);

    $run = RemoteCliRun::query()->where('command', 'theme install')->sole();
    expect($run->args)->toBe(['twentytwentyfive', '--version=1.4', '--force']);
});

test('child theme scaffolds from an installed parent without activating it', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('themes', [['name' => 'twentytwentyfour', 'title' => 'Twenty Twenty-Four', 'status' => 'active', 'version' => '1.6', 'update' => 'none', 'auto_update' => 'off']])
        ->call('createChildTheme', 'twentytwentyfour')
        ->assertHasNoErrors();

    $run = RemoteCliRun::query()->where('command', 'scaffold child-theme')->sole();
    expect($run->args)->toBe(['twentytwentyfour-child', '--parent_theme=twentytwentyfour', '--theme_name=Twenty Twenty-Four Child']);
});

test('child theme is refused for a theme that is not installed', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('createChildTheme', 'not-here')
        ->assertHasErrors('themes');

    expect(RemoteCliRun::query()->count())->toBe(0);
});

/** Users tab rows as loadUsers() shapes them. */
function wpUsersFixture(): array
{
    return [
        ['id' => '1', 'login' => 'admin', 'name' => 'Site Admin', 'email' => 'admin@example.com', 'roles' => 'administrator'],
        ['id' => '2', 'login' => 'jo', 'name' => 'Jo Writer', 'email' => 'jo@example.com', 'roles' => 'editor'],
    ];
}

test('creating a user sends the password to the box but never stores it', function () {
    [$user, $site] = makeWpSite();

    $shell = [];
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturnUsing(function ($server, $name, $bash) use (&$shell) {
            $shell[] = $bash;

            return new ProcessOutput('3', 0, false);
        });
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('users', wpUsersFixture())
        ->set('newUserLogin', 'newbie')
        ->set('newUserEmail', 'newbie@example.com')
        ->set('newUserRole', 'author')
        ->call('createUser')
        ->assertHasNoErrors();

    $password = $component->instance()->revealedUserPassword();
    expect($password)->toBeString()->not->toBe('');

    $run = RemoteCliRun::query()->where('command', 'user create')->sole();
    expect($run->args)->toBe(['newbie', 'newbie@example.com', '--role=author', '--porcelain', '--user_pass=[redacted]']);

    // The queue is faked in this file: the real value rides only in the
    // (encrypted) job payload. Run that job to prove the box gets it…
    $job = Queue::pushed(RunRemoteCliInBackgroundJob::class)->sole();
    expect($job->secrets)->toBe(['user_pass' => $password]);
    $job->handle(app(ExecuteRemoteTaskOnServer::class), app(SiteAuditWriter::class));

    expect(implode("\n", $shell))->toContain('--user_pass='.$password)
        // …while the run row and the audit event (written by the job) never hold it.
        ->and(RemoteCliRun::query()->get()->toJson())->not->toContain($password)
        ->and(SiteAuditEvent::query()->get()->toJson())->not->toContain($password)
        ->and(SiteAuditEvent::query()->count())->toBeGreaterThan(0);
});

test('a queued run whose secret is missing fails closed instead of running the placeholder', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    $run = RemoteCliRun::query()->create([
        'site_id' => $site->id,
        'kind' => Kind::Wp,
        'command' => 'user update',
        'args' => ['admin', '--skip-email', '--user_pass=[redacted]'],
        'risk' => RiskLevel::MutatingRecoverable,
        'mode' => RemoteCliRun::MODE_ASYNC,
        'status' => RemoteCliRun::STATUS_QUEUED,
        'queued_by_user_id' => $user->id,
    ]);

    // No secrets in the payload.
    (new RunRemoteCliInBackgroundJob($run->id))
        ->handle($executor, app(SiteAuditWriter::class));

    expect($run->fresh()->status)->toBe(RemoteCliRun::STATUS_FAILED);
});

test('the only administrator cannot be deleted', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('users', wpUsersFixture())
        ->call('startDeleteUser', '1')
        ->assertHasErrors('users')
        ->assertSet('deletingUserId', null);
});

test('deleting a user hands their content to the chosen heir', function () {
    [$user, $site] = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('users', wpUsersFixture())
        ->call('startDeleteUser', '2')
        // Defaults to another administrator.
        ->assertSet('deleteReassignTo', '1')
        ->call('deleteUser')
        ->assertSet('deletingUserId', null);

    $run = RemoteCliRun::query()->where('command', 'user delete')->sole();
    expect($run->args)->toBe(['2', '--reassign=1', '--yes']);
});

test('the user list filters by role and search text', function () {
    [$user, $site] = makeWpSite();

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('users', wpUsersFixture())
        ->set('userRoleFilter', 'editor');
    expect(array_column($component->instance()->filteredUsers(), 'login'))->toBe(['jo']);

    $component->set('userRoleFilter', '')->set('userFilter', 'ADMIN@');
    expect(array_column($component->instance()->filteredUsers(), 'login'))->toBe(['admin']);
});

/** WordPress.org core APIs, as captured live (2026-09-10). */
function fakeCoreReleases(): void
{
    Http::fake([
        'api.wordpress.org/core/version-check/*' => Http::response(['offers' => [
            ['current' => '7.1', 'php_version' => '7.4'],
            ['current' => '6.8.8', 'php_version' => '7.2.24'],
        ]]),
        'api.wordpress.org/core/stable-check/*' => Http::response(['7.1' => 'latest', '6.8.8' => 'outdated']),
    ]);
}

test('downgrading core warns that the database schema stays newer, then pins the version', function () {
    [$user, $site] = makeWpSite();
    fakeCoreReleases();

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('core', ['version' => '7.1', 'update_available' => false, 'latest' => null])
        ->set('coreTargetVersion', '6.8.8')
        ->call('confirmCoreVersionChange')
        ->assertSet('confirmActionModalMethod', 'changeCoreVersion');

    expect($component->get('confirmActionModalWarning'))->toContain('schema');

    $component->call('confirmActionModal');

    $run = RemoteCliRun::query()->where('command', 'core update')->sole();
    expect($run->args)->toBe(['--version=6.8.8', '--force']);
});

test('a core version outside the branch list is refused', function () {
    [$user, $site] = makeWpSite();
    fakeCoreReleases();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('changeCoreVersion', '5.0')
        ->assertHasErrors('core');

    expect(RemoteCliRun::query()->count())->toBe(0);
});

test('bedrock sites refuse core file changes even when called directly', function () {
    [$user, $site] = makeWpSite();
    $site->update(['meta' => ['scaffold' => ['framework' => 'wordpress', 'layout' => 'bedrock']]]);
    fakeCoreReleases();

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('core', ['version' => '7.1', 'update_available' => true, 'latest' => '7.1'])
        ->call('changeCoreVersion', '7.1')
        ->assertHasErrors('core')
        ->call('repairCore', '7.1')
        ->call('updateCore')
        ->call('setCoreAutoUpdate', 'minor');

    expect(RemoteCliRun::query()->count())->toBe(0);
});

test('repair re-lays only the installed version, core files only', function () {
    [$user, $site] = makeWpSite();

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('core', ['version' => '7.1', 'update_available' => false, 'latest' => null])
        ->call('repairCore', '6.8.8')
        ->assertHasErrors('core');
    expect(RemoteCliRun::query()->count())->toBe(0);

    $component->call('repairCore', '7.1');

    $run = RemoteCliRun::query()->where('command', 'core download')->sole();
    expect($run->args)->toBe(['--version=7.1', '--force', '--skip-content']);
});

test('the automatic core update policy writes the wp-config constant', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('setCoreAutoUpdate', 'minor')
        ->assertSet('coreAutoUpdate', 'minor');

    $run = RemoteCliRun::query()->where('command', 'config set')->sole();
    expect($run->args)->toBe(['WP_AUTO_UPDATE_CORE', 'minor', '--type=constant']);
});

/** Stub one instant wp-cli read: the executor streams $json back as stdout. */
function fakeWpRead(string $json): void
{
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->withArgs(function ($s, $name, $bash, callable $cb) use ($json) {
            $cb('out', $json);

            return true;
        })
        ->andReturn(new ProcessOutput($json, 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);
}

test('take snapshot queues the dump instead of running it in the request', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('takeSnapshot')
        ->assertHasNoErrors();

    Queue::assertPushed(TakeSiteSnapshotJob::class);
});

test('largest tables parse wp-cli byte sizes and sort biggest first', function () {
    [$user, $site] = makeWpSite();
    fakeWpRead(json_encode([
        ['Name' => 'wp_options', 'Size' => '2048 B'],
        ['Name' => 'wp_postmeta', 'Size' => '9000000 B'],
        ['Name' => 'wp_posts', 'Size' => '500000 B'],
    ]));

    $tables = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('loadDbTables')
        ->get('dbTables');

    expect(array_column($tables, 'name'))->toBe(['wp_postmeta', 'wp_posts', 'wp_options'])
        ->and($tables[0]['bytes'])->toBe(9000000);
});

test('revision cleanup targets the main site tables on multisite, after confirming', function () {
    [$user, $site] = makeWpSite();

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('dbTables', [
            ['name' => 'wp_2_posts', 'bytes' => 1], ['name' => 'wp_2_options', 'bytes' => 1],
            ['name' => 'wp_posts', 'bytes' => 1], ['name' => 'wp_options', 'bytes' => 1],
            ['name' => 'wp_postmeta', 'bytes' => 1],
        ])
        ->call('confirmDbCleanup', 'revisions')
        ->assertSet('confirmActionModalMethod', 'runDbCleanup');

    expect(RemoteCliRun::query()->count())->toBe(0);
    $component->call('confirmActionModal');

    $sql = RemoteCliRun::query()->where('command', 'db query')->sole()->args[0];
    expect($sql)->toContain("`wp_posts` WHERE post_type = 'revision'")
        ->toContain('`wp_postmeta` pm LEFT JOIN `wp_posts`')
        ->not->toContain('wp_2_');
});

test('members cannot run the permanent cleanups, even calling the method directly', function () {
    [$user, $site] = makeWpSite(userRole: 'member');

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('dbTables', [['name' => 'wp_posts', 'bytes' => 1], ['name' => 'wp_options', 'bytes' => 1]])
        ->call('confirmDbCleanup', 'revisions')
        ->assertHasErrors('database')
        ->call('runDbCleanup', 'comments');

    expect(RemoteCliRun::query()->where('command', 'db query')->count())->toBe(0);
});

test('the autoload audit counts what WordPress actually autoloads', function () {
    [$user, $site] = makeWpSite();
    fakeWpRead(json_encode([
        ['option_name' => 'big_plugin_cache', 'size_bytes' => '500', 'autoload' => 'yes'],
        ['option_name' => 'new_style', 'size_bytes' => '300', 'autoload' => 'auto'],
        ['option_name' => 'not_loaded', 'size_bytes' => '900', 'autoload' => 'off'],
        ['option_name' => 'opted_out', 'size_bytes' => '100', 'autoload' => 'auto-off'],
    ]));

    $audit = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('loadAutoloadAudit')
        ->get('autoloadAudit');

    // wp-cli's own --autoload=on would miss the 6.6 "auto" row.
    expect($audit['total'])->toBe(800)
        ->and($audit['count'])->toBe(2)
        ->and($audit['top'][0]['name'])->toBe('big_plugin_cache');
});

/** Stub wp-cli by command: the first needle found in the built shell line wins. */
function fakeWpCommands(array $map): void
{
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturnUsing(function ($server, $name, $bash, callable $cb) use ($map) {
            foreach ($map as $needle => [$out, $exit]) {
                if (str_contains($bash, $needle)) {
                    $cb($exit === 0 ? 'out' : 'err', $out);

                    return new ProcessOutput($out, $exit, false);
                }
            }

            return new ProcessOutput('', 0, false);
        });
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);
}

test('cron events flag what is long overdue and filter by hook', function () {
    [$user, $site] = makeWpSite();

    $view = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('cronEvents', [
            ['hook' => 'wp_version_check', 'next_run_gmt' => now('UTC')->subHour()->format('Y-m-d H:i:s'), 'recurrence' => '12 hours'],
            ['hook' => 'woocommerce_cleanup', 'next_run_gmt' => now('UTC')->addHour()->format('Y-m-d H:i:s'), 'recurrence' => '1 day'],
        ])
        ->set('cronEventFilter', 'woo')
        ->instance()->cronEventRows();

    expect($view['overdue'])->toBe(1)
        ->and(array_column($view['rows'], 'hook'))->toBe(['woocommerce_cleanup'])
        ->and($view['rows'][0]['overdue'])->toBeFalse();
});

test('run all due and unschedule queue the right cron commands', function () {
    [$user, $site] = makeWpSite();

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('runDueCronEvents')
        ->call('confirmDeleteCronEvent', 'bad hook; rm')
        ->assertHasErrors('cron')
        ->call('confirmDeleteCronEvent', 'my_plugin_sync')
        ->assertSet('confirmActionModalMethod', 'deleteCronEvent');
    $component->call('confirmActionModal');

    expect(RemoteCliRun::query()->where('command', 'cron event run')->sole()->args)->toBe(['--due-now'])
        ->and(RemoteCliRun::query()->where('command', 'cron event delete')->sole()->args)->toBe(['my_plugin_sync']);
});

test('saving site identity only writes what changed and refuses a leading dash', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('siteSettings', ['blogname' => 'Old', 'blogdescription' => 'Same', 'blog_public' => '1', 'home' => null, 'siteurl' => null, 'debug' => false])
        ->set('settingsTitle', '--flag')
        ->set('settingsTagline', 'Same')
        ->call('saveSiteIdentity')
        ->assertHasErrors('tools')
        ->set('settingsTitle', 'New name')
        ->call('saveSiteIdentity');

    expect(RemoteCliRun::query()->where('command', 'option update')->sole()->args)->toBe(['blogname', 'New name']);
});

test('changing the site address confirms first, and bedrock refuses', function () {
    [$user, $site] = makeWpSite();

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('settingsHome', 'https://new.example.com/')
        ->set('settingsSiteurl', 'https://new.example.com')
        ->call('confirmSiteAddress')
        ->assertSet('confirmActionModalMethod', 'saveSiteAddress');
    expect(RemoteCliRun::query()->count())->toBe(0);
    $component->call('confirmActionModal');

    expect(RemoteCliRun::query()->where('command', 'option update')->pluck('args')->all())
        ->toBe([['home', 'https://new.example.com'], ['siteurl', 'https://new.example.com']]);

    $site->update(['meta' => ['scaffold' => ['framework' => 'wordpress', 'layout' => 'bedrock']]]);
    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('saveSiteAddress', 'https://x.example.com', 'https://x.example.com')
        ->assertHasErrors('core');
    expect(RemoteCliRun::query()->count())->toBe(2);
});

test('debug logging logs to a file and never displays, admin only', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('setDebugLogging', true);

    expect(RemoteCliRun::query()->where('command', 'config set')->pluck('args')->all())->toBe([
        ['WP_DEBUG', 'true', '--raw', '--type=constant'],
        ['WP_DEBUG_LOG', 'true', '--raw', '--type=constant'],
        ['WP_DEBUG_DISPLAY', 'false', '--raw', '--type=constant'],
    ]);

    [$member, $memberSite] = makeWpSite(userRole: 'member');
    Livewire::actingAs($member)
        ->test(WordPressSection::class, ['site' => $memberSite])
        ->call('setDebugLogging', true)
        ->assertHasErrors('tools');
});

test('the security scan reads the real state and scores each check', function () {
    [$user, $site] = makeWpSite();
    fakeCoreReleases();
    $advisories = Mockery::mock(AdvisoryProvider::class);
    $advisories->shouldReceive('forPlugin')->andReturn([]);
    app()->instance(AdvisoryProvider::class, $advisories);

    fakeWpCommands([
        'core version' => ['6.8.8', 0],
        'plugin list' => [json_encode([['name' => 'hello', 'status' => 'active', 'version' => '1.7', 'update' => 'none']]), 0],
        'theme list' => [json_encode([
            ['name' => 'twentytwentyfive', 'status' => 'active', 'version' => '1.0', 'update' => 'none'],
            ['name' => 'twentytwentyfour', 'status' => 'inactive', 'version' => '1.0', 'update' => 'none'],
            ['name' => 'twentytwentythree', 'status' => 'inactive', 'version' => '1.0', 'update' => 'none'],
        ]), 0],
        'user list' => [json_encode([['ID' => 1, 'user_login' => 'admin', 'display_name' => 'Admin', 'user_email' => 'a@example.com', 'roles' => 'administrator']]), 0],
        "'users_can_register'" => ['1', 0],
        "'default_role'" => ['administrator', 0],
        "'DISALLOW_FILE_EDIT'" => ['true', 0],
        "'FORCE_SSL_ADMIN'" => ["Error: The constant 'FORCE_SSL_ADMIN' is not defined in the 'wp-config.php' file.", 1],
        "'WP_DEBUG_DISPLAY'" => ["Error: The constant 'WP_DEBUG_DISPLAY' is not defined in the 'wp-config.php' file.", 1],
        "'WP_DEBUG'" => ['true', 0],
    ]);

    $scan = collect(Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('runSecurityScan')
        ->get('securityScan'))->pluck('status', 'key');

    expect($scan->all())->toMatchArray([
        'core' => 'warn',          // 6.8.8 is "outdated"
        'plugins' => 'pass',
        'login' => 'warn',         // no login-protection plugin
        'themes' => 'warn',        // two unused themes
        'users' => 'warn',         // an account named admin
        'registration' => 'fail',  // open, as administrator
        'file_edit' => 'pass',
        'ssl_admin' => 'warn',     // not defined
        // WP_DEBUG on with WP_DEBUG_DISPLAY undefined: WordPress displays errors.
        'debug' => 'fail',
    ]);
});

test('hardening quick fixes queue the right work, and wp-cron is left to the Cron tab', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->call('lockDownRegistration')
        ->call('resetFilePermissions')
        ->call('toggleHardening', 'disallow_file_mods')
        ->call('toggleHardening', 'disable_wp_cron')
        ->assertHasErrors('hardening');

    expect(RemoteCliRun::query()->where('command', 'option update')->pluck('args')->all())
        ->toBe([['users_can_register', '0'], ['default_role', 'subscriber']])
        ->and(RemoteCliRun::query()->where('command', 'config set')->sole()->args)->toBe(['DISALLOW_FILE_MODS', 'true', '--raw', '--type=constant']);
    Queue::assertPushed(SiteResetPermissionsJob::class);
});

test('reset password takes a typed password as a secret and never echoes it back', function () {
    [$user, $site] = makeWpSite();
    $typed = 'my own pa$$"word';

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('users', wpUsersFixture())
        ->call('startResetUserPassword', 'jo')
        ->assertSet('resettingPasswordLogin', 'jo')
        ->set('resetPasswordValue', $typed)
        ->call('resetUserPassword', 'jo')
        ->assertHasNoErrors()
        ->assertSet('resetPasswordValue', '')
        ->assertSet('resettingPasswordLogin', null);

    expect($component->instance()->revealedUserPassword())->toBeNull();

    $run = RemoteCliRun::query()->where('command', 'user update')->sole();
    expect($run->args)->toBe(['jo', '--skip-email', '--user_pass=[redacted]'])
        ->and(Queue::pushed(RunRemoteCliInBackgroundJob::class)->sole()->secrets)->toBe(['user_pass' => $typed]);
});

test('reset password rejects a short typed password and a blank one generates', function () {
    [$user, $site] = makeWpSite();

    $component = Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('users', wpUsersFixture())
        ->call('startResetUserPassword', 'jo')
        ->set('resetPasswordValue', 'short')
        ->call('resetUserPassword', 'jo')
        ->assertHasErrors('users');

    expect(RemoteCliRun::query()->count())->toBe(0);

    $component->call('resetUserPassword', 'jo')->assertHasNoErrors();

    expect($component->instance()->revealedUserPassword())->toBeString()->not->toBe('')
        ->and($component->get('resettingPasswordLogin'))->toBe('jo');
});

test('database tab shows remote access for the site database', function () {
    [$user, $site] = makeWpSite();
    $db = \App\Models\ServerDatabase::factory()->create([
        'server_id' => $site->server_id,
        'name' => 'dply_wpremote',
        'username' => 'dply_wpremote',
        'engine' => 'mysql84',
        'host' => 'localhost',
    ]);
    $site->update(['meta' => ['scaffold' => ['framework' => 'wordpress', 'database' => ['server_database_id' => $db->id]]]]);
    // ready() sets only the status; a tunnel also needs the box's SSH key.
    $site->server->update(['ssh_private_key' => 'test-key']);

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site->fresh()])
        ->set('tab', 'database')
        ->assertSee('Remote access')
        ->assertSee('dply_wpremote')
        ->assertSeeHtml(':127.0.0.1:3306')
        // The password never reaches the page.
        ->assertDontSee('secret');
});

test('database tab has no remote access card when no database resolves', function () {
    [$user, $site] = makeWpSite();

    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->set('tab', 'database')
        ->assertDontSee('Remote access');
});
