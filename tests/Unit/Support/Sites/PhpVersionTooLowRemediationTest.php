<?php

declare(strict_types=1);

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Livewire\Sites\DeploymentsList;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Modules\Remediations\Jobs\ApplyRemediationJob;
use App\Modules\Remediations\Services\Actions\UpgradePhpAction;
use App\Modules\Remediations\Services\RemediationCatalog;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ServerPhpManager;
use App\Support\Sites\SitePipelineAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function composerPhpMismatchLog(): string
{
    return implode("\n", [
        'Your requirements could not be resolved to an installable set of packages.',
        '  Problem 22',
        '    - Root composer.json requires php >=8.2',
        '    - symfony/translation v8.1.4 requires php >=8.4.1 -> your php version (8.3.6) does not satisfy that requirement.',
        '    - nesbot/carbon v3.11.1 requires php >=8.4.1 -> your php version (8.3.6) does not satisfy that requirement.',
    ]);
}

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
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
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ]);

    return [$user, $server, $site];
}

test('composer php mismatch matches php_version_too_low', function () {
    $remediation = app(RemediationCatalog::class)->match(composerPhpMismatchLog());

    expect($remediation['code'] ?? null)->toBe('php_version_too_low')
        ->and(collect($remediation['actions'] ?? [])->pluck('key'))->toContain('upgrade_php')
        ->and(collect($remediation['actions'] ?? [])->firstWhere('key', 'upgrade_php')['handler'] ?? null)
        ->toBe(UpgradePhpAction::class);
});

test('pipeline check suggests upgrading php after a composer version failure', function () {
    [, $appServer, $site] = phpUpgradeSite();

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => composerPhpMismatchLog(),
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $suggestion = collect(SitePipelineAdvisor::suggestions($site->fresh()))->firstWhere('key', 'upgrade_php');

    expect($suggestion)->not->toBeNull()
        ->and($suggestion['action'])->toBe('upgrade_php')
        ->and($suggestion['command'])->toBe('8.4')
        ->and($suggestion['priority'])->toBe('high')
        ->and($suggestion['phase'])->toBe('runtime');
    expect($appServer->id)->not->toBeEmpty();
});

test('add fix on the php upgrade suggestion queues the remediation', function () {
    Queue::fake();
    [$user, $appServer, $site] = phpUpgradeSite();

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => composerPhpMismatchLog(),
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $appServer, 'site' => $site])
        ->assertSee(__('Upgrade PHP to :version', ['version' => '8.4']))
        ->call('addSuggestedPipelineStep', 'upgrade_php');

    Queue::assertPushed(ApplyRemediationJob::class, function (ApplyRemediationJob $job) use ($appServer, $site): bool {
        return $job->serverId === (string) $appServer->id
            && $job->siteId === (string) $site->id
            && $job->code === 'php_version_too_low'
            && $job->actionKey === 'upgrade_php';
    });
});

test('upgrade php action installs the version and switches the site', function () {
    Queue::fake();
    [, $appServer, $site] = phpUpgradeSite();

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => composerPhpMismatchLog(),
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $php = Mockery::mock(ServerPhpManager::class);
    $php->shouldReceive('applyPackageAction')
        ->twice()
        ->andReturn(['status' => 'succeeded', 'message' => 'ok', 'output' => 'installed']);
    app()->instance(ServerPhpManager::class, $php);

    $error = (new UpgradePhpAction)->apply($appServer, $site->fresh(), null, new ConsoleEmitter);

    expect($error)->toBeNull()
        ->and($site->fresh()->runtime_version)->toBe('8.4');

    Queue::assertPushed(ApplySiteWebserverConfigJob::class);
});
