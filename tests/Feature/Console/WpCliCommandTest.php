<?php

declare(strict_types=1);

namespace Tests\Feature\Console\WpCliCommandTest;

use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
function makeWpSite(string $userRole = 'admin'): Site
{
    $user = User::factory()->create(['email' => 'admin@example.com']);
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $userRole]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
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
test('runs instant command and streams output', function () {
    $site = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->withArgs(function ($s, $name, $bash, callable $cb) {
            $cb('out', 'akismet,active,5.3');

            return true;
        })
        ->andReturn(new ProcessOutput('akismet,active,5.3', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    $exit = Artisan::call('dply:wp', [
        'site' => 'shopco',
        'args' => ['plugin', 'list', '--format=csv'],
        '--user' => 'admin@example.com',
    ]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('akismet,active,5.3', Artisan::output());

    $run = RemoteCliRun::query()->sole();
    expect($run->command)->toBe('plugin list');
    expect($run->args)->toBe(['--format=csv']);
});
test('emits json envelope when json flag passed', function () {
    $site = makeWpSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->withArgs(function ($s, $name, $bash, callable $cb) {
            $cb('out', '[]');

            return true;
        })
        ->andReturn(new ProcessOutput('[]', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    Artisan::call('dply:wp', [
        'site' => 'shopco',
        'args' => ['plugin', 'list'],
        '--user' => 'admin@example.com',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true);
    expect($payload)->toBeArray();
    expect($payload['exit_code'])->toBe(0);
    expect($payload['status'])->toBe('completed');
    expect($payload['risk'])->toBe('read');
});
test('no args after double dash is a user error', function () {
    makeWpSite();
    $exit = Artisan::call('dply:wp', ['site' => 'shopco']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Pass the wp-cli invocation', Artisan::output());
});
test('unknown site is a user error', function () {
    $exit = Artisan::call('dply:wp', ['site' => 'nope', 'args' => ['plugin', 'list']]);
    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site [nope] not found', Artisan::output());
});
test('destructive command with no confirm skips prompt', function () {
    // Bus::fake so the dispatched async job doesn't run inline and
    // collide with the unmocked executor.
    Bus::fake();
    $site = makeWpSite(userRole: 'admin');

    $exit = Artisan::call('dply:wp', [
        'site' => 'shopco',
        'args' => ['db', 'drop', '--yes'],
        '--user' => 'admin@example.com',
        '--no-confirm' => true,
    ]);

    // Async run returned successfully (queued); exit_code is null
    // because the run hasn't completed yet — the umbrella reports
    // SUCCESS for queued runs since the dispatch worked.
    expect($exit)->toBe(0);
    $run = RemoteCliRun::query()->sole();
    expect($run->risk->value)->toBe('destructive');
    expect($run->status)->toBe('queued');
});
test('member role is blocked for destructive', function () {
    makeWpSite(userRole: 'member');

    $exit = Artisan::call('dply:wp', [
        'site' => 'shopco',
        'args' => ['db', 'drop'],
        '--user' => 'admin@example.com',
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Permission denied', Artisan::output());
});
