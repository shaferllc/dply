<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sites;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\ByoRepoConfigSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Support\FakeRemoteShell;

uses(RefreshDatabase::class);

test('byo repo config sync creates site and server scoped cron rows', function (): void {
    Feature::define('global.byo_repo_config', fn (): bool => true);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
    ]);

    $yaml = <<<'YAML'
crons:
  - schedule: "0 * * * *"
    command: php artisan schedule:run

server_crons:
  - schedule: "15 2 * * *"
    command: /usr/local/bin/dply-backup-runner
    user: root
YAML;

    $shell = new FakeRemoteShell(function (string $command) use ($yaml): ?string {
        if (str_contains($command, 'dply.yaml')) {
            return $yaml;
        }

        return null;
    });

    $result = app(ByoRepoConfigSync::class)->syncAfterDeploy($site, $shell, '/var/www/app/current');

    expect($result['applied'])->toBeTrue()
        ->and($result['crons'])->toBe(1)
        ->and($result['server_crons'])->toBe(1);

    $siteCron = ServerCronJob::query()
        ->where('server_id', $server->id)
        ->where('site_id', $site->id)
        ->first();

    $serverCron = ServerCronJob::query()
        ->where('server_id', $server->id)
        ->whereNull('site_id')
        ->first();

    expect($siteCron)->not->toBeNull()
        ->and($siteCron->description)->toStartWith(ByoRepoConfigSync::MANAGED_CRON_PREFIX)
        ->and($serverCron)->not->toBeNull()
        ->and($serverCron->description)->toStartWith(ByoRepoConfigSync::MANAGED_SERVER_CRON_PREFIX)
        ->and($serverCron->user)->toBe('root');
});
