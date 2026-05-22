<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Services\Servers\ServerCronCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerCronCommandBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_wraps_with_flock_when_overlap_skip(): void
    {
        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'ssh_user' => 'deploy',
        ]);
        $job = ServerCronJob::query()->create([
            'server_id' => $server->id,
            'cron_expression' => '* * * * *',
            'command' => 'echo hi',
            'user' => 'deploy',
            'overlap_policy' => ServerCronJob::OVERLAP_SKIP_IF_RUNNING,
        ]);

        $segment = app(ServerCronCommandBuilder::class)->crontabCommandSegment($server->fresh(), $job->fresh());

        $this->assertStringStartsWith('flock -n ', $segment);
        $this->assertStringContainsString($job->getKey(), $segment);
    }

    public function test_prepends_timezone_export(): void
    {
        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'ssh_user' => 'deploy',
        ]);
        $job = ServerCronJob::query()->create([
            'server_id' => $server->id,
            'cron_expression' => '* * * * *',
            'command' => 'echo hi',
            'user' => 'deploy',
            'schedule_timezone' => 'America/New_York',
        ]);

        $inner = app(ServerCronCommandBuilder::class)->buildInnerShellCommand($job->fresh());

        $this->assertStringContainsString('export TZ=', $inner);
        $this->assertStringContainsString('America/New_York', $inner);
        $this->assertStringNotContainsString("\n", $inner, 'inner segment must stay on one crontab line');
    }

    public function test_flattens_multiline_env_prefix_and_command_onto_one_line(): void
    {
        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'ssh_user' => 'deploy',
        ]);
        $job = ServerCronJob::query()->create([
            'server_id' => $server->id,
            'cron_expression' => '0 6 * * 0',
            'command' => "apt-get update -qq\napt-get -y -qq upgrade",
            'user' => 'deploy',
            'schedule_timezone' => 'UTC',
            'env_prefix' => "export DEBIAN_FRONTEND=noninteractive\n# weekly upgrade\nexport PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin",
        ]);

        $segment = app(ServerCronCommandBuilder::class)->crontabCommandSegment($server, $job->fresh());

        $this->assertStringNotContainsString("\n", $segment, 'crontab segment must be a single line');
        $this->assertStringNotContainsString("\r", $segment);
        // Comments inside env_prefix are dropped so they can't be parsed as crontab.
        $this->assertStringNotContainsString('# weekly upgrade', $segment);
        // The pieces are present in declaration order: TZ → env → command.
        $tzPos = strpos($segment, 'TZ=');
        $envPos = strpos($segment, 'DEBIAN_FRONTEND=noninteractive');
        $cmdPos = strpos($segment, 'apt-get update');
        $this->assertNotFalse($tzPos);
        $this->assertNotFalse($envPos);
        $this->assertNotFalse($cmdPos);
        $this->assertLessThan($envPos, $tzPos);
        $this->assertLessThan($cmdPos, $envPos);
    }
}
