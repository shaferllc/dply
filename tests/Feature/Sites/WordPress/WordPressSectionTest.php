<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\WordPress;

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
use Tests\TestCase;

class WordPressSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeWpSite(string $userRole = 'admin'): array
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

    public function test_section_renders_friendly_placeholder_for_non_wordpress_site(): void
    {
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
    }

    public function test_renders_default_console_tab(): void
    {
        [$user, $site] = $this->makeWpSite();

        Livewire::actingAs($user)
            ->test(WordPressSection::class, ['site' => $site])
            ->assertSet('tab', 'console')
            ->assertSee('wp-cli Console');
    }

    public function test_run_console_command_creates_remotecli_run(): void
    {
        [$user, $site] = $this->makeWpSite();

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
        $this->assertSame(Kind::Wp, $run->kind);
        $this->assertSame('plugin list', $run->command);
        $this->assertSame(['--format=csv'], $run->args);
        $this->assertSame('completed', $run->status);
    }

    public function test_console_blocks_destructive_for_member_with_friendly_error(): void
    {
        [$user, $site] = $this->makeWpSite(userRole: 'member');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldNotReceive('runInlineBashWithOutputCallback');
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        Livewire::actingAs($user)
            ->test(WordPressSection::class, ['site' => $site])
            ->set('consoleCommand', 'db drop')
            ->call('runConsoleCommand')
            ->assertHasErrors('consoleCommand');

        $this->assertSame(0, RemoteCliRun::query()->count());
    }

    public function test_console_rejects_empty_command(): void
    {
        [$user, $site] = $this->makeWpSite();

        Livewire::actingAs($user)
            ->test(WordPressSection::class, ['site' => $site])
            ->set('consoleCommand', '')
            ->call('runConsoleCommand')
            ->assertHasErrors('consoleCommand');

        $this->assertSame(0, RemoteCliRun::query()->count());
    }

    public function test_cron_tab_shows_default_handler(): void
    {
        [$user, $site] = $this->makeWpSite();

        Livewire::actingAs($user)
            ->test(WordPressSection::class, ['site' => $site])
            ->set('tab', 'cron')
            ->assertSee('wp-cron via HTTP (default)')
            ->assertSee('Switch to system cron');
    }

    public function test_load_plugins_runs_wp_plugin_list_and_decorates_with_advisories(): void
    {
        [$user, $site] = $this->makeWpSite();

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
        $this->assertCount(2, $plugins);
        $this->assertSame('akismet', $plugins[0]['name']);
        $this->assertSame('available', $plugins[0]['update']);
        $this->assertCount(1, $plugins[0]['advisories']);
        $this->assertSame('CVE-2024-X', $plugins[0]['advisories'][0]['cve']);
        $this->assertCount(0, $plugins[1]['advisories']);
    }

    public function test_load_plugins_handles_empty_install_gracefully(): void
    {
        [$user, $site] = $this->makeWpSite();

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
    }

    public function test_update_all_plugins_dispatches_async_command(): void
    {
        [$user, $site] = $this->makeWpSite();

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
        $this->assertSame(['--all'], $run->args);
    }

    public function test_database_tab_renders_empty_state_when_no_snapshots(): void
    {
        [$user, $site] = $this->makeWpSite();

        Livewire::actingAs($user)
            ->test(WordPressSection::class, ['site' => $site])
            ->set('tab', 'database')
            ->assertSee('Database snapshots')
            ->assertSee('No snapshots yet');
    }

    public function test_take_snapshot_blocks_member_role_with_inline_error(): void
    {
        [$user, $site] = $this->makeWpSite(userRole: 'member');

        Livewire::actingAs($user)
            ->test(WordPressSection::class, ['site' => $site])
            ->set('tab', 'database')
            ->call('takeSnapshot')
            ->assertHasErrors('snapshots');

        $this->assertSame(0, Snapshot::query()->count());
    }

    public function test_database_tab_lists_existing_snapshots(): void
    {
        [$user, $site] = $this->makeWpSite();
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
    }

    public function test_hardening_tab_renders_existing_opinions_as_toggles(): void
    {
        [$user, $site] = $this->makeWpSite();
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
    }

    public function test_toggle_hardening_runs_wp_config_set_and_updates_meta(): void
    {
        [$user, $site] = $this->makeWpSite();
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
        $this->assertTrue($opinion['enabled']);

        $run = RemoteCliRun::query()->where('command', 'config set')->sole();
        $this->assertSame(['FORCE_SSL_ADMIN', 'true', '--raw', '--type=constant'], $run->args);
    }

    public function test_toggle_hardening_off_runs_wp_config_delete(): void
    {
        [$user, $site] = $this->makeWpSite();
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
        $this->assertFalse($opinion['enabled']);

        $run = RemoteCliRun::query()->where('command', 'config delete')->sole();
        $this->assertSame(['DISALLOW_FILE_EDIT', '--type=constant'], $run->args);
    }

    public function test_toggle_hardening_blocks_member_role(): void
    {
        [$user, $site] = $this->makeWpSite(userRole: 'member');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldNotReceive('runInlineBashWithOutputCallback');
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        Livewire::actingAs($user)
            ->test(WordPressSection::class, ['site' => $site])
            ->set('tab', 'hardening')
            ->call('toggleHardening', 'disable_wp_cron')
            ->assertHasErrors('hardening');

        $this->assertSame(0, RemoteCliRun::query()->count());
    }

    public function test_toggle_hardening_rejects_unknown_opinion(): void
    {
        [$user, $site] = $this->makeWpSite();

        Livewire::actingAs($user)
            ->test(WordPressSection::class, ['site' => $site])
            ->set('tab', 'hardening')
            ->call('toggleHardening', 'totally-fake-opinion')
            ->assertHasErrors('hardening');
    }

    public function test_switch_to_system_cron_records_handler_on_meta(): void
    {
        [$user, $site] = $this->makeWpSite();

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
        $this->assertSame('system_cron', $site->meta['wp_cron']['handler']);
    }
}
