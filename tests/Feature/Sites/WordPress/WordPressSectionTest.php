<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\WordPress;

use App\Enums\SiteType;
use App\Livewire\Sites\WordPress\WordPressSection;
use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\RemoteCli\Kind;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
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
