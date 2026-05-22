<?php

declare(strict_types=1);

namespace Tests\Feature\Console\GranularCliCommandsTest;
use Mockery;

use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\Site;
use App\Models\Snapshot;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
function makeSite(string $userRole = 'admin'): Site
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
test('snapshot take creates local snapshot row', function () {
    $site = makeSite();
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

    expect($exit)->toBe(0);
    $snapshot = Snapshot::query()->sole();
    expect($snapshot->destination)->toBe(Snapshot::DESTINATION_LOCAL_DISK);
    expect($snapshot->reason)->toBe('manual');
});
test('snapshot take with json emits envelope', function () {
    $site = makeSite();

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
    expect($payload)->toBeArray();
    expect($payload['destination'])->toBe('local_disk');
    expect($payload['engine'])->toBe('mysql84');
});
test('snapshot take rejects s3 destination when unconfigured', function () {
    makeSite();
    config(['snapshot_s3.enabled' => false, 'snapshot_s3.bucket' => null]);

    $exit = Artisan::call('dply:snapshot:take', [
        'site' => 'shopco',
        '--destination' => 's3',
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('S3 destination requested but no bucket', Artisan::output());
});
test('snapshot list renders table for existing snapshots', function () {
    $site = makeSite();
    Snapshot::factory()->count(3)->create(['site_id' => $site->id, 'reason' => 'manual', 'bytes' => 2048]);

    $exit = Artisan::call('dply:snapshot:list', ['site' => 'shopco']);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    $this->assertStringContainsString('snap-', $output);
    $this->assertStringContainsString('local_disk', $output);
});
test('snapshot list empty state', function () {
    makeSite();
    Artisan::call('dply:snapshot:list', ['site' => 'shopco']);
    $this->assertStringContainsString('No snapshots.', Artisan::output());
});
test('wp search replace runs async with canonical safe flags', function () {
    Bus::fake();
    makeSite();

    $exit = Artisan::call('dply:wp:search-replace', [
        'site' => 'shopco',
        'from' => 'http://old.example.com',
        'to' => 'https://new.example.com',
        '--user' => 'admin@example.com',
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    $run = RemoteCliRun::query()->sole();
    expect($run->command)->toBe('search-replace');
    expect($run->args)->toContain('--all-tables');
    expect($run->args)->toContain('--skip-columns=guid');
});
test('wp search replace dry run appends flag', function () {
    Bus::fake();
    makeSite();

    Artisan::call('dply:wp:search-replace', [
        'site' => 'shopco',
        'from' => 'a', 'to' => 'b',
        '--user' => 'admin@example.com',
        '--dry-run' => true,
    ]);

    $run = RemoteCliRun::query()->sole();
    expect($run->args)->toContain('--dry-run');
});
test('wp hardening apply runs three config set calls and records meta', function () {
    Bus::fake();
    $site = makeSite();

    Artisan::call('dply:wp:hardening:apply', [
        'site' => 'shopco',
        '--user' => 'admin@example.com',
    ]);

    // Three config set runs queued (one per constant).
    $runs = RemoteCliRun::query()->where('command', 'config set')->get();
    expect($runs)->toHaveCount(3);

    $constants = $runs->map(fn ($r) => $r->args[0])->all();
    expect($constants)->toEqualCanonicalizing(['DISALLOW_FILE_EDIT', 'FORCE_SSL_ADMIN', 'DISABLE_WP_CRON']);

    $site->refresh();
    $hardening = collect($site->meta['scaffold']['hardening']);
    expect($hardening)->toHaveCount(3);
    expect($hardening->every(fn ($r) => $r['enabled'] === true))->toBeTrue();
});
test('wp cron switch to system disables wp cron constant', function () {
    Bus::fake();
    $site = makeSite();

    Artisan::call('dply:wp:cron:switch', [
        'site' => 'shopco',
        '--to' => 'system',
        '--user' => 'admin@example.com',
    ]);

    $run = RemoteCliRun::query()->where('command', 'config set')->sole();
    expect($run->args)->toBe(['DISABLE_WP_CRON', 'true', '--raw', '--type=constant']);

    $site->refresh();
    expect($site->meta['wp_cron']['handler'])->toBe('system_cron');
});
test('wp cron switch back to wp cron deletes constant', function () {
    Bus::fake();
    $site = makeSite();

    Artisan::call('dply:wp:cron:switch', [
        'site' => 'shopco',
        '--to' => 'wp-cron',
        '--user' => 'admin@example.com',
    ]);

    $run = RemoteCliRun::query()->where('command', 'config delete')->sole();
    expect($run->args)->toBe(['DISABLE_WP_CRON', '--type=constant']);

    $site->refresh();
    expect($site->meta['wp_cron']['handler'])->toBe('wp_cron');
});
test('wp cron switch rejects unknown target', function () {
    makeSite();
    $exit = Artisan::call('dply:wp:cron:switch', [
        'site' => 'shopco',
        '--to' => 'bogus',
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('--to must be', Artisan::output());
});
test('wp plugin update all dispatches async run', function () {
    Bus::fake();
    makeSite();

    Artisan::call('dply:wp:plugin:update-all', [
        'site' => 'shopco',
        '--user' => 'admin@example.com',
    ]);

    $run = RemoteCliRun::query()->sole();
    expect($run->command)->toBe('plugin update');
    expect($run->args)->toBe(['--all']);
    expect($run->status)->toBe('queued');
});
test('laravel migrate rollback takes safety snapshot then dispatches', function () {
    Bus::fake();
    $site = makeSite();

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

    expect($exit)->toBe(0);

    $snapshot = Snapshot::query()->where('reason', Snapshot::REASON_PRE_MIGRATION_ROLLBACK)->sole();
    expect($snapshot->site_id)->toBe($site->id);

    $run = RemoteCliRun::query()->where('command', 'migrate:rollback')->sole();
    expect($run->args)->toBe(['--force', '--step=2']);
});
test('laravel migrate rollback no snapshot skips safety net', function () {
    Bus::fake();
    makeSite();

    Artisan::call('dply:laravel:migrate:rollback', [
        'site' => 'shopco',
        '--user' => 'admin@example.com',
        '--no-snapshot' => true,
        '--no-confirm' => true,
    ]);

    expect(Snapshot::query()->count())->toBe(0, '--no-snapshot must skip the pre-rollback dump entirely');
});
test('snapshot restore aborts when user declines confirmation', function () {
    $site = makeSite();
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

    expect($exit)->toBe(1);
});
