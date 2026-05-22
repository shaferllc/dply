<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CleanupRemoteSiteArtifactsJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SiteDeleteSystemdTeardownTest extends TestCase
{
    use RefreshDatabase;

    public function test_node_site_deletion_passes_unit_names_to_cleanup_job(): void
    {
        Queue::fake();

        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'node',
            'start_command' => 'npm start',
            'internal_port' => 30001,
        ]);
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'worker',
            'command' => 'npm run worker',
        ]);

        $expectedWeb = "dply-site-{$site->id}.service";
        $expectedWorker = "dply-site-{$site->id}-worker.service";

        $site->delete();

        Queue::assertPushed(CleanupRemoteSiteArtifactsJob::class, function (CleanupRemoteSiteArtifactsJob $job) use ($expectedWeb, $expectedWorker) {
            $names = $job->payload['systemd_unit_names'] ?? [];

            return in_array($expectedWeb, $names, true)
                && in_array($expectedWorker, $names, true);
        });
    }

    public function test_php_site_deletion_omits_systemd_unit_names(): void
    {
        Queue::fake();

        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'php',
        ]);

        $site->delete();

        Queue::assertPushed(CleanupRemoteSiteArtifactsJob::class, function (CleanupRemoteSiteArtifactsJob $job) {
            return ($job->payload['systemd_unit_names'] ?? []) === [];
        });
    }
}
