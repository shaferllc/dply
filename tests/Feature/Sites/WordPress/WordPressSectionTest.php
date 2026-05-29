<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\WordPress\WordPressSectionTest;

use App\Enums\SiteType;
use App\Livewire\Sites\WordPress\WordPressSection;
use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\Site;
use App\Models\Snapshot;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\RemoteCli\Kind;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\WordPress\Advisories\Advisory;
use App\Services\WordPress\Advisories\AdvisoryProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
test('list action buttons hidden for member role', function () {
    [$user, $site] = makeWpSite(userRole: 'member');

    // Members can run reads + recoverable, so the gate flag we surface
    // for mutating actions should still be true; only destructive is
    // gated. Confirm the canMutate flag reaches the view.
    Livewire::actingAs($user)
        ->test(WordPressSection::class, ['site' => $site])
        ->assertViewHas('canMutate', true)
        ->assertViewHas('canDestroy', false);
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
        ->call('switchToSystemCron');

    $site->refresh();
    expect($site->meta['wp_cron']['handler'])->toBe('system_cron');
});
