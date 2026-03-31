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
    }
}
