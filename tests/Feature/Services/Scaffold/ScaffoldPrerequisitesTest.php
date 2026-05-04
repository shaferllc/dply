<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Scaffold;

use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Scaffold\PrerequisiteResult;
use App\Services\Scaffold\ScaffoldPrerequisites;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ScaffoldPrerequisitesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function server(): Server
    {
        $user = User::factory()->create();

        return Server::factory()->ready()->create(['user_id' => $user->id]);
    }

    public function test_wp_cli_already_present_is_a_no_op(): void
    {
        $server = $this->server();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        // The presence check returns 0 (test -x); install must NOT run.
        $executor->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(fn ($s, string $name) => $name === 'scaffold-prerequisites:check')
            ->andReturn(new ProcessOutput('', 0, false));

        $result = (new ScaffoldPrerequisites($executor))->ensureWpCli($server);

        $this->assertSame(PrerequisiteResult::STATE_PRESENT, $result->state);
        $this->assertTrue($result->ok());
    }

    public function test_wp_cli_missing_triggers_install(): void
    {
        $server = $this->server();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(fn ($s, string $name) => $name === 'scaffold-prerequisites:check')
            ->andReturn(new ProcessOutput('', 1, false)); // not present
        $executor->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(function ($s, string $name, string $bash): bool {
                $this->assertSame('scaffold-prerequisites:install-wp-cli', $name);
                $this->assertStringContainsString('wp-cli.phar', $bash);

                return true;
            })
            ->andReturn(new ProcessOutput('wp-cli 2.x', 0, false));

        $result = (new ScaffoldPrerequisites($executor))->ensureWpCli($server);

        $this->assertSame(PrerequisiteResult::STATE_INSTALLED, $result->state);
        $this->assertTrue($result->ok());
    }

    public function test_wp_cli_install_failure_is_propagated(): void
    {
        $server = $this->server();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->withArgs(fn ($s, string $name) => $name === 'scaffold-prerequisites:check')
            ->andReturn(new ProcessOutput('', 1, false));
        $executor->shouldReceive('runInlineBash')
            ->withArgs(fn ($s, string $name) => $name === 'scaffold-prerequisites:install-wp-cli')
            ->andReturn(new ProcessOutput('curl: (6) Could not resolve host', 6, false));

        $result = (new ScaffoldPrerequisites($executor))->ensureWpCli($server);

        $this->assertSame(PrerequisiteResult::STATE_FAILED, $result->state);
        $this->assertFalse($result->ok());
        $this->assertStringContainsString('Could not resolve host', $result->error);
    }

    public function test_composer_already_present_is_a_no_op(): void
    {
        $server = $this->server();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(fn ($s, string $name) => $name === 'scaffold-prerequisites:check')
            ->andReturn(new ProcessOutput('', 0, false));

        $result = (new ScaffoldPrerequisites($executor))->ensureComposer($server);

        $this->assertSame(PrerequisiteResult::STATE_PRESENT, $result->state);
    }

    public function test_missing_for_returns_binary_name_when_absent(): void
    {
        $server = $this->server();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->andReturn(new ProcessOutput('', 1, false)); // not present

        $svc = new ScaffoldPrerequisites($executor);

        $this->assertSame('wp-cli', $svc->missingFor($server, 'wordpress'));
        $this->assertSame('composer', $svc->missingFor($server, 'laravel'));
        $this->assertNull($svc->missingFor($server, 'unknown-framework'));
    }

    public function test_missing_for_returns_null_when_present(): void
    {
        $server = $this->server();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->andReturn(new ProcessOutput('', 0, false));

        $svc = new ScaffoldPrerequisites($executor);

        $this->assertNull($svc->missingFor($server, 'wordpress'));
        $this->assertNull($svc->missingFor($server, 'laravel'));
    }
}
