<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\PhpUpgradeConsoleVisibilityTest;

use App\Livewire\Sites\DeploymentsList;
use App\Models\ConsoleAction;
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

function phpUpgradeSite(): array
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
        'log_output' => 'your php version (8.3.6) does not satisfy that requirement. requires php >=8.4.1',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    return [$user, $server, $site];
}

test('upgrade php seeds a console run and watches it at the top of deploy', function () {
    Queue::fake();
    [$user, $server, $site] = phpUpgradeSite();

    $component = Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('addSuggestedPipelineStep', 'upgrade_php');

    Queue::assertPushed(ApplyRemediationJob::class, function (ApplyRemediationJob $job) use ($site, $server): bool {
        return $job->serverId === (string) $server->id
            && $job->siteId === (string) $site->id
            && $job->code === 'php_version_too_low'
            && $job->actionKey === 'upgrade_php'
            && filled($job->seededConsoleRunId);
    });

    $run = ConsoleAction::query()
        ->where('subject_id', $site->id)
        ->where('kind', 'remediation_apply')
        ->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(ConsoleAction::STATUS_QUEUED)
        ->and($component->get('watchedConsoleRunId'))->toBe((string) $run->id);
});

test('a second upgrade click attaches to the running fix instead of dispatching again', function () {
    Queue::fake();
    [$user, $server, $site] = phpUpgradeSite();

    $existing = ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'remediation_apply',
        'status' => ConsoleAction::STATUS_RUNNING,
        'started_at' => now(),
        'label' => 'Applying fix',
        'output' => ['v' => 1, 'lines' => []],
    ]);

    $component = Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('addSuggestedPipelineStep', 'upgrade_php');

    Queue::assertNotPushed(ApplyRemediationJob::class);
    expect($component->get('watchedConsoleRunId'))->toBe((string) $existing->id);
});

test('the in-flight php upgrade beats a newer failed lock run on the banner', function () {
    [$user, $server, $site] = phpUpgradeSite();

    $running = ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'remediation_apply',
        'status' => ConsoleAction::STATUS_RUNNING,
        'started_at' => now()->subMinutes(2),
        'created_at' => now()->subMinutes(2),
        'output' => ['v' => 1, 'lines' => []],
    ]);

    ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'remediation_apply',
        'status' => ConsoleAction::STATUS_FAILED,
        'error' => 'Another PHP package action is already running for this server.',
        'finished_at' => now(),
        'created_at' => now(),
        'output' => ['v' => 1, 'lines' => []],
    ]);

    $component = Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site]);

    expect($component->instance()->deploymentRemediationRun?->id)->toBe($running->id)
        ->and($component->instance()->activeConsoleRun()?->id)->toBe($running->id);
});
