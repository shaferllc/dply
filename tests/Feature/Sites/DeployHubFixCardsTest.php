<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\DeployHubFixCardsTest;

use App\Livewire\Sites\DeploymentsList;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Modules\Remediations\Jobs\ApplyRemediationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function deployHubFixCardsSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
        'runtime_version' => '8.3',
        'meta' => [
            'vm_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'version' => '^8.4',
                ],
            ],
        ],
    ]);

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => implode("\n", [
            'your php version (8.3.6) does not satisfy that requirement. requires php >=8.4.1',
            'Class "Redis" not found in vendor/laravel/framework/src/Illuminate/Redis/Connectors/PhpRedisConnector.php',
        ]),
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    return [$user, $server, $site];
}

test('the deploy hub shows php upgrade and phpredis as separate cards', function () {
    [$user, $server, $site] = deployHubFixCardsSite();

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->assertSee(__('Upgrade PHP to :version', ['version' => '8.4']))
        ->assertSee('PHP Redis extension (phpredis) is missing')
        ->assertDontSee('dply recognized this failure')
        ->assertDontSee('Suggested fix')
        ->assertDontSee('Pipeline check')
        ->assertDontSee('Install the PHP Redis extension');
});

test('applying the redis card still works when php is the first catalog match', function () {
    Queue::fake();
    [$user, $server, $site] = deployHubFixCardsSite();
    $deployment = $site->latestDeployment();

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('applyDeploymentRemediation', (string) $deployment->id, 'install_phpredis');

    Queue::assertPushed(ApplyRemediationJob::class, function (ApplyRemediationJob $job) use ($server, $site): bool {
        return $job->serverId === (string) $server->id
            && $job->siteId === (string) $site->id
            && $job->code === 'php_ext_redis_missing'
            && $job->actionKey === 'install_phpredis';
    });
});
