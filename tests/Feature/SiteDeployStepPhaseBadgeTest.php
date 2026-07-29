<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDeployStepPhaseBadgeTest;

use App\Livewire\Sites\WorkspacePipeline;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\User;
use App\Modules\Deploy\Services\SiteDeployPipelineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('workspace.deploy_pipeline_visual');

test('dashboard shows phase badges on each deploy step', function () {
    [$user, $server] = makeUserServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'runtime' => 'php',
        'runtime_version' => '8.4',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    $manager = app(SiteDeployPipelineManager::class);
    $pipeline = $manager->ensureDefaultPipeline($site);
    $manager->addStep($pipeline, SiteDeployStep::TYPE_COMPOSER_INSTALL, null, 600);
    $manager->addStep($pipeline, SiteDeployStep::TYPE_ARTISAN_MIGRATE, null, 300);
    $manager->primeSiteForPipelineWorkspace($site);

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->set('editingPipelineId', (string) $pipeline->id)
        ->assertOk()
        ->assertSee('build')
        ->assertSee('release')
        ->assertSee(SiteDeployStep::TYPE_COMPOSER_INSTALL)
        ->assertSee(SiteDeployStep::TYPE_ARTISAN_MIGRATE);
});

/**
 * @return array{0: User, 1: Server}
 */
function makeUserServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'webserver' => 'nginx',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4'],
            ],
        ],
    ]);

    return [$user, $server];
}
