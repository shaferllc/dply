<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDeleteSystemdTeardownTest;
use App\Jobs\CleanupRemoteSiteArtifactsJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Support\Facades\Queue;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('node site deletion passes unit names to cleanup job', function () {
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
});
test('php site deletion omits systemd unit names', function () {
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
});
