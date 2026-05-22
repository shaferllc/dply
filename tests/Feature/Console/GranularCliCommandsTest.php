<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\Site;
use App\Models\Snapshot;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Cross-command tests for the granular dply:* surfaces (Q21):
 * snapshot:take/list/restore, wp:search-replace, wp:hardening:apply,
 * wp:cron:switch, wp:plugin:update-all, laravel:migrate:rollback.
 *
 * Each test exercises one command end-to-end against the same
 * mocked SSH executor pattern used by the umbrella + UI tests so
 * the granular commands stay verified to use the real services
 * (not bypass them).
 */
class GranularCliCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeSite(string $userRole = 'admin'): Site
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $userRole]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['database' => 'mysql84'],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'slug' => 'shopco',
            'name' => 'shopco',
            'document_root' => '/home/dply/shopco/current',
            'meta' => ['scaffold' => ['framework' => 'wordpress']],
        ]);
    }

    public function test_snapshot_take_creates_local_snapshot_row(): void
    {
        $site = $this->makeSite();
        config(['snapshot_s3.enabled' => false]);

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        // Three calls: dump, size, local-stash mv.
        $executor->shouldReceive('runInlineBash')
            ->andReturn(new ProcessOutput("4096\n", 0, false));
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        $exit = Artisan::call('dply:snapshot:take', [
            'site' => 'shopco',
            '--user' => 'admin@example.com',
            '--destination' => 'local',
        ]);

        $this->assertSame(0, $exit);
        $snapshot = Snapshot::query()->sole();
        $this->assertSame(Snapshot::DESTINATION_LOCAL_DISK, $snapshot->destination);
        $this->assertSame('manual', $snapshot->reason);
    }

    public function test_snapshot_take_with_json_emits_envelope(): void
    {
        $site = $this->makeSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->andReturn(new ProcessOutput("100\n", 0, false));
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        Artisan::call('dply:snapshot:take', [
            'site' => 'shopco',
            '--user' => 'admin@example.com',
            '--destination' => 'local',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true);
        $this->assertIsArray($payload);
        $this->assertSame('local_disk', $payload['destination']);
        $this->assertSame('mysql84', $payload['engine']);
    }

    public function test_snapshot_take_rejects_s3_destination_when_unconfigured(): void
    {
        $this->makeSite();
        config(['snapshot_s3.enabled' => false, 'snapshot_s3.bucket' => null]);

        $exit = Artisan::call('dply:snapshot:take', [
            'site' => 'shopco',
            '--destination' => 's3',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('S3 destination requested but no bucket', Artisan::output());
    }

    public function test_snapshot_list_renders_table_for_existing_snapshots(): void
    {
        $site = $this->makeSite();
        Snapshot::factory()->count(3)->create(['site_id' => $site->id, 'reason' => 'manual', 'bytes' => 2048]);

        $exit = Artisan::call('dply:snapshot:list', ['site' => 'shopco']);

        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('snap-', $output);
        $this->assertStringContainsString('local_disk', $output);
    }

    public function test_snapshot_list_empty_state(): void
    {
        $this->makeSite();
        Artisan::call('dply:snapshot:list', ['site' => 'shopco']);
        $this->assertStringContainsString('No snapshots.', Artisan::output());
    }

    public function test_wp_search_replace_runs_async_with_canonical_safe_flags(): void
    {
        Bus::fake();
        $this->makeSite();

        $exit = Artisan::call('dply:wp:search-replace', [
            'site' => 'shopco',
            'from' => 'http://old.example.com',
            'to' => 'https://new.example.com',
            '--user' => 'admin@example.com',
            '--no-confirm' => true,
        ]);

        $this->assertSame(0, $exit);
        $run = RemoteCliRun::query()->sole();
        $this->assertSame('search-replace', $run->command);
        $this->assertContains('--all-tables', $run->args);
        $this->assertContains('--skip-columns=guid', $run->args);
    }

    public function test_wp_search_replace_dry_run_appends_flag(): void
    {
        Bus::fake();
        $this->makeSite();

        Artisan::call('dply:wp:search-replace', [
            'site' => 'shopco',
            'from' => 'a', 'to' => 'b',
            '--user' => 'admin@example.com',
            '--dry-run' => true,
        ]);

        $run = RemoteCliRun::query()->sole();
        $this->assertContains('--dry-run', $run->args);
    }

    public function test_wp_hardening_apply_runs_three_config_set_calls_and_records_meta(): void
    {
        Bus::fake();
        $site = $this->makeSite();

        Artisan::call('dply:wp:hardening:apply', [
            'site' => 'shopco',
            '--user' => 'admin@example.com',
        ]);

        // Three config set runs queued (one per constant).
        $runs = RemoteCliRun::query()->where('command', 'config set')->get();
        $this->assertCount(3, $runs);

        $constants = $runs->map(fn ($r) => $r->args[0])->all();
        $this->assertEqualsCanonicalizing(
            ['DISALLOW_FILE_EDIT', 'FORCE_SSL_ADMIN', 'DISABLE_WP_CRON'],
            $constants
        );

        $site->refresh();
        $hardening = collect($site->meta['scaffold']['hardening']);
        $this->assertCount(3, $hardening);
        $this->assertTrue($hardening->every(fn ($r) => $r['enabled'] === true));
    }

    public function test_wp_cron_switch_to_system_disables_wp_cron_constant(): void
    {
        Bus::fake();
        $site = $this->makeSite();

        Artisan::call('dply:wp:cron:switch', [
            'site' => 'shopco',
            '--to' => 'system',
            '--user' => 'admin@example.com',
        ]);

        $run = RemoteCliRun::query()->where('command', 'config set')->sole();
        $this->assertSame(['DISABLE_WP_CRON', 'true', '--raw', '--type=constant'], $run->args);

        $site->refresh();
        $this->assertSame('system_cron', $site->meta['wp_cron']['handler']);
    }

    public function test_wp_cron_switch_back_to_wp_cron_deletes_constant(): void
    {
        Bus::fake();
        $site = $this->makeSite();

        Artisan::call('dply:wp:cron:switch', [
            'site' => 'shopco',
            '--to' => 'wp-cron',
            '--user' => 'admin@example.com',
        ]);

        $run = RemoteCliRun::query()->where('command', 'config delete')->sole();
        $this->assertSame(['DISABLE_WP_CRON', '--type=constant'], $run->args);

        $site->refresh();
        $this->assertSame('wp_cron', $site->meta['wp_cron']['handler']);
    }

    public function test_wp_cron_switch_rejects_unknown_target(): void
    {
        $this->makeSite();
        $exit = Artisan::call('dply:wp:cron:switch', [
            'site' => 'shopco',
            '--to' => 'bogus',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--to must be', Artisan::output());
    }

    public function test_wp_plugin_update_all_dispatches_async_run(): void
    {
        Bus::fake();
        $this->makeSite();

        Artisan::call('dply:wp:plugin:update-all', [
            'site' => 'shopco',
            '--user' => 'admin@example.com',
        ]);

        $run = RemoteCliRun::query()->sole();
        $this->assertSame('plugin update', $run->command);
        $this->assertSame(['--all'], $run->args);
        $this->assertSame('queued', $run->status);
    }

    public function test_laravel_migrate_rollback_takes_safety_snapshot_then_dispatches(): void
    {
        Bus::fake();
        $site = $this->makeSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        // Snapshot dump + size + local-stash (3 calls)
        $executor->shouldReceive('runInlineBash')
            ->andReturn(new ProcessOutput("100\n", 0, false));
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        $exit = Artisan::call('dply:laravel:migrate:rollback', [
            'site' => 'shopco',
            '--step' => 2,
            '--user' => 'admin@example.com',
            '--no-confirm' => true,
        ]);

        $this->assertSame(0, $exit);

        $snapshot = Snapshot::query()->where('reason', Snapshot::REASON_PRE_MIGRATION_ROLLBACK)->sole();
        $this->assertSame($site->id, $snapshot->site_id);

        $run = RemoteCliRun::query()->where('command', 'migrate:rollback')->sole();
        $this->assertSame(['--force', '--step=2'], $run->args);
    }

    public function test_laravel_migrate_rollback_no_snapshot_skips_safety_net(): void
    {
        Bus::fake();
        $this->makeSite();

        Artisan::call('dply:laravel:migrate:rollback', [
            'site' => 'shopco',
            '--user' => 'admin@example.com',
            '--no-snapshot' => true,
            '--no-confirm' => true,
        ]);

        $this->assertSame(0, Snapshot::query()->count(),
            '--no-snapshot must skip the pre-rollback dump entirely');
    }

    public function test_snapshot_restore_aborts_when_user_declines_confirmation(): void
    {
        $site = $this->makeSite();
        $snapshot = Snapshot::factory()->create([
            'site_id' => $site->id,
            'destination' => Snapshot::DESTINATION_LOCAL_DISK,
            'local_path' => '/home/dply/snap.sql.gz',
            'engine' => 'mysql84',
        ]);

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldNotReceive('runInlineBash');
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        // Without --no-confirm the prompt fires; in non-interactive
        // test mode the answer is "no", so we expect FAILURE.
        $exit = Artisan::call('dply:snapshot:restore', ['snapshot' => $snapshot->id]);

        $this->assertSame(1, $exit);
    }
}
