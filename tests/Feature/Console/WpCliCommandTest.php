<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

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
use Tests\TestCase;

class WpCliCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeWpSite(string $userRole = 'admin'): Site
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

    public function test_runs_instant_command_and_streams_output(): void
    {
        $site = $this->makeWpSite();

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

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('akismet,active,5.3', Artisan::output());

        $run = RemoteCliRun::query()->sole();
        $this->assertSame('plugin list', $run->command);
        $this->assertSame(['--format=csv'], $run->args);
    }

    public function test_emits_json_envelope_when_json_flag_passed(): void
    {
        $site = $this->makeWpSite();

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
        $this->assertIsArray($payload);
        $this->assertSame(0, $payload['exit_code']);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame('read', $payload['risk']);
    }

    public function test_no_args_after_double_dash_is_a_user_error(): void
    {
        $this->makeWpSite();
        $exit = Artisan::call('dply:wp', ['site' => 'shopco']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pass the wp-cli invocation', Artisan::output());
    }

    public function test_unknown_site_is_a_user_error(): void
    {
        $exit = Artisan::call('dply:wp', ['site' => 'nope', 'args' => ['plugin', 'list']]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site [nope] not found', Artisan::output());
    }

    public function test_destructive_command_with_no_confirm_skips_prompt(): void
    {
        // Bus::fake so the dispatched async job doesn't run inline and
        // collide with the unmocked executor.
        Bus::fake();
        $site = $this->makeWpSite(userRole: 'admin');

        $exit = Artisan::call('dply:wp', [
            'site' => 'shopco',
            'args' => ['db', 'drop', '--yes'],
            '--user' => 'admin@example.com',
            '--no-confirm' => true,
        ]);

        // Async run returned successfully (queued); exit_code is null
        // because the run hasn't completed yet — the umbrella reports
        // SUCCESS for queued runs since the dispatch worked.
        $this->assertSame(0, $exit);
        $run = RemoteCliRun::query()->sole();
        $this->assertSame('destructive', $run->risk->value);
        $this->assertSame('queued', $run->status);
    }

    public function test_member_role_is_blocked_for_destructive(): void
    {
        $this->makeWpSite(userRole: 'member');

        $exit = Artisan::call('dply:wp', [
            'site' => 'shopco',
            'args' => ['db', 'drop'],
            '--user' => 'admin@example.com',
            '--no-confirm' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Permission denied', Artisan::output());
    }
}
