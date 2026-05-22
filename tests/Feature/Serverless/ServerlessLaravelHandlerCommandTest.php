<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use RuntimeException;
use Tests\TestCase;

/**
 * Verifies the Laravel adapter's background-tick command authorisation by
 * loading the real handler file and calling dply_do_functions_command().
 *
 * Regression guard for the production bug where every Laravel tick failed
 * with "invalid command secret": the secret is delivered in the bundled
 * .env, but DigitalOcean Functions does not promote .env keys to real
 * environment variables — so a getenv()-only lookup always saw an empty
 * secret. The command must resolve it from the parsed .env.
 */
class ServerlessLaravelHandlerCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The handler file only declares functions (function_exists-guarded);
        // requiring it has no side effects.
        require_once resource_path('serverless/digitalocean-functions-laravel-handler.php');
    }

    public function test_a_tick_authorises_when_the_secret_is_in_the_bundled_env(): void
    {
        // The exact production scenario: the secret lives only in .env, never
        // as a real environment variable.
        $task = dply_do_functions_command(
            ['__ow_headers' => ['x-dply-run' => 'schedule', 'x-dply-secret' => 's3cret']],
            ['DPLY_COMMAND_SECRET' => 's3cret'],
        );

        $this->assertSame(['schedule:run', []], $task);
    }

    public function test_a_queue_tick_returns_the_queue_worker_command(): void
    {
        $task = dply_do_functions_command(
            ['__ow_headers' => ['x-dply-run' => 'queue', 'x-dply-secret' => 's3cret']],
            ['DPLY_COMMAND_SECRET' => 's3cret'],
        );

        $this->assertSame('queue:work', $task[0]);
    }

    public function test_a_mismatched_secret_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid command secret');

        dply_do_functions_command(
            ['__ow_headers' => ['x-dply-run' => 'schedule', 'x-dply-secret' => 'wrong']],
            ['DPLY_COMMAND_SECRET' => 's3cret'],
        );
    }

    public function test_an_absent_secret_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        dply_do_functions_command(
            ['__ow_headers' => ['x-dply-run' => 'schedule', 'x-dply-secret' => 'anything']],
            [],
        );
    }

    public function test_a_normal_request_is_not_a_command(): void
    {
        $this->assertNull(dply_do_functions_command(['__ow_headers' => ['x-dply-path' => '/']], []));
        $this->assertNull(dply_do_functions_command([], []));
    }
}
