<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\Laravel;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\Snapshot;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * PR 11b — Laravel Migrations sub-tab. Loader runs `migrate:status
 * --json` (INSTANT-allowlisted, sync); rollback action runs
 * `migrate:rollback --force --step=1` AFTER taking a pre-rollback
 * safety snapshot (Q19 transient case via SnapshotService).
 */
class SettingsLaravelMigrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeLaravelSite(string $userRole = 'admin'): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $userRole]);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['database' => 'mysql84'],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Php,
            'document_root' => '/home/dply/app/current',
            'meta' => [
                'vm_runtime' => ['detected' => ['framework' => 'laravel', 'language' => 'php']],
            ],
        ]);

        return [$user, $server, $site];
    }

    public function test_load_migrations_populates_entries_from_artisan_status_json(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $statusJson = json_encode([
            ['migration' => '2014_10_12_000000_create_users_table', 'batch' => 1, 'ran' => true],
            ['migration' => '2026_05_03_100000_add_remote_cli_runs_table', 'batch' => 2, 'ran' => true],
            ['migration' => '2026_05_04_000000_add_pending_table', 'batch' => null, 'ran' => false],
        ]);

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBashWithOutputCallback')
            ->once()
            ->withArgs(function ($s, $name, $bash, callable $cb) use ($statusJson) {
                $this->assertStringContainsString('php artisan migrate:status', $bash);
                $cb('out', $statusJson);

                return true;
            })
            ->andReturn(new ProcessOutput($statusJson, 0, false));
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        $component = Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'migrations')
            ->call('loadLaravelMigrations')
            ->assertSet('laravelMigrationsLoaded', true);

        $entries = $component->get('laravelMigrationEntries');
        $this->assertCount(3, $entries);
        $this->assertSame('2014_10_12_000000_create_users_table', $entries[0]['migration']);
        $this->assertTrue($entries[0]['ran']);
        $this->assertFalse($entries[2]['ran']);
    }

    public function test_rollback_takes_pre_rollback_snapshot_then_runs_artisan(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        // Snapshot service: dump → size → local-disk stash (3 calls)
        $executor->shouldReceive('runInlineBash')
            ->andReturn(new ProcessOutput("4096\n", 0, false));
        // Artisan async dispatch: migrate:rollback → callback driven
        $executor->shouldReceive('runInlineBashWithOutputCallback')
            ->withArgs(function ($s, $name, $bash, callable $cb) {
                $cb('out', 'Rolled back: 2026_05_04_000000_add_pending_table');

                return true;
            })
            ->andReturn(new ProcessOutput('Rolled back', 0, false));
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        $component = Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'migrations')
            ->call('rollbackLastMigrationBatch');

        // Snapshot row exists with the pre-rollback reason
        $snapshot = Snapshot::query()->where('reason', Snapshot::REASON_PRE_MIGRATION_ROLLBACK)->sole();
        $this->assertSame($site->id, $snapshot->site_id);

        // Flash message references the snapshot id
        $this->assertStringContainsString('snap-'.$snapshot->id, (string) $component->get('laravelMigrationsFlash'));
    }

    public function test_rollback_blocks_non_admin_role(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite(userRole: 'member');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldNotReceive('runInlineBash');
        $executor->shouldNotReceive('runInlineBashWithOutputCallback');
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'migrations')
            ->call('rollbackLastMigrationBatch')
            ->assertHasErrors('laravel_migrations');

        $this->assertSame(0, Snapshot::query()->count());
    }
}
