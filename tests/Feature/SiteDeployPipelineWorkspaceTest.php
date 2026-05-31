<?php

declare(strict_types=1);

use App\Livewire\Sites\WorkspacePipeline;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployStep;
use App\Models\User;
use App\Services\Deploy\SiteDeployPipelineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithOrganization(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('site can have multiple deploy pipelines and reorder steps', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $manager = app(SiteDeployPipelineManager::class);
    $default = $manager->ensureDefaultPipeline($site);
    $stepA = $manager->addStep($default, SiteDeployStep::TYPE_COMPOSER_INSTALL, null, 600);
    $stepB = $manager->addStep($default, SiteDeployStep::TYPE_NPM_CI, null, 900);

    $staging = $manager->createPipeline($site, 'Staging', $default->id);
    $manager->activatePipeline($site, $staging);

    expect($site->fresh()->active_deploy_pipeline_id)->toBe($staging->id)
        ->and($site->deployPipelines()->count())->toBe(2);

    $manager->reorderSteps($default, [(string) $stepB->id, (string) $stepA->id]);

    expect($default->fresh()->steps()->orderBy('sort_order')->pluck('id')->all())
        ->toBe([(string) $stepB->id, (string) $stepA->id]);
});

test('reorder build steps keeps release steps after build block', function () {
    $site = Site::factory()->create();
    $manager = app(SiteDeployPipelineManager::class);
    $pipeline = $manager->ensureDefaultPipeline($site);
    $buildA = $manager->addStep($pipeline, SiteDeployStep::TYPE_COMPOSER_INSTALL, null, 600, null, SiteDeployStep::PHASE_BUILD);
    $buildB = $manager->addStep($pipeline, SiteDeployStep::TYPE_NPM_CI, null, 900, null, SiteDeployStep::PHASE_BUILD);
    $migrate = $manager->addStep($pipeline, SiteDeployStep::TYPE_ARTISAN_MIGRATE, null, 900, null, SiteDeployStep::PHASE_RELEASE);

    $manager->reorderBuildSteps($pipeline, [(string) $buildB->id, (string) $buildA->id]);

    expect($pipeline->fresh()->steps()->orderBy('sort_order')->pluck('id')->all())
        ->toBe([(string) $buildB->id, (string) $buildA->id, (string) $migrate->id]);
});

test('pipeline workspace supports creating pipeline and applying template', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
    ]);

    app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    $this->actingAs($user)
        ->get(route('sites.pipeline', ['server' => $server, 'site' => $site, 'tab' => 'steps'], false))
        ->assertOk()
        ->assertSee('Pipelines')
        ->assertSee('Dply templates')
        ->assertSee('Add hooks');

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->set('new_pipeline_name', 'Quick deploy')
        ->call('createDeployPipeline')
        ->assertHasNoErrors();

    expect($site->fresh()->deployPipelines()->where('name', 'Quick deploy')->exists())->toBeTrue();
});

test('duplicate pipeline step opens confirmation before adding', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    app(SiteDeployPipelineManager::class)->addStep(
        $pipeline,
        SiteDeployStep::TYPE_COMPOSER_INSTALL,
        null,
        900,
    );

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->set('editingPipelineId', (string) $pipeline->id)
        ->call('addDeployPipelineStepFromPalette', SiteDeployStep::TYPE_COMPOSER_INSTALL)
        ->assertSet('show_duplicate_pipeline_step_modal', true)
        ->assertSee('Add duplicate step?')
        ->call('closeDuplicatePipelineStepModal')
        ->assertSet('show_duplicate_pipeline_step_modal', false);

    expect($pipeline->fresh()->steps()->where('step_type', SiteDeployStep::TYPE_COMPOSER_INSTALL)->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->set('editingPipelineId', (string) $pipeline->id)
        ->call('addDeployPipelineStepFromPalette', SiteDeployStep::TYPE_COMPOSER_INSTALL)
        ->call('confirmAddDuplicatePipelineStep')
        ->assertSet('show_duplicate_pipeline_step_modal', false);

    expect($pipeline->fresh()->steps()->where('step_type', SiteDeployStep::TYPE_COMPOSER_INSTALL)->count())->toBe(2);
});

test('pipeline workspace can edit custom deploy step', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $step = app(SiteDeployPipelineManager::class)->addStep(
        $pipeline,
        SiteDeployStep::TYPE_CUSTOM,
        'php artisan horizon:publish',
        600,
        null,
        SiteDeployStep::PHASE_BUILD,
    );

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->set('editingPipelineId', (string) $pipeline->id)
        ->call('openEditPipelineStep', $step->id)
        ->assertSet('editing_deploy_step_id', $step->id)
        ->assertSet('new_deploy_step_command', 'php artisan horizon:publish')
        ->assertSee('Save pipeline')
        ->assertSeeHtml('wire:target="')
        ->set('new_deploy_step_command', 'php artisan queue:restart')
        ->call('savePipelineWorkspace')
        ->assertSet('show_pipeline_step_form', false);

    expect($step->fresh()->custom_command)->toBe('php artisan queue:restart');
});

test('pipeline clone anchor script can be edited and saved', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $customClone = '{GIT_SSH_PREFIX}git clone --branch {BRANCH} {REPO_URL} {RELEASE_DIR}';

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->call('openEditPipelineAnchor', 'clone')
        ->assertDispatched('open-modal', 'pipeline-anchor-form')
        ->assertSet('show_pipeline_anchor_form', true)
        ->assertSet('editing_pipeline_anchor', 'clone')
        ->set('pipeline_clone_script', $customClone)
        ->call('savePipelineWorkspace')
        ->assertSet('show_pipeline_anchor_form', false);

    expect($pipeline->fresh()->clone_script)->toBe($customClone);
});

test('pipeline step form sets dirty flag for unsaved bar', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $step = app(SiteDeployPipelineManager::class)->addStep(
        $pipeline,
        SiteDeployStep::TYPE_CUSTOM,
        'php artisan horizon',
        600,
    );

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->call('openEditPipelineStep', $step->id)
        ->assertSet('pipeline_step_form_dirty', false)
        ->set('new_deploy_step_command', 'php artisan queue:restart')
        ->assertSet('pipeline_step_form_dirty', true)
        ->assertSet('pipeline_form_edits_pending', true);
});

test('addDeployPipelineHookFromPalette opens configure form for shell at anchor', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->set('editingPipelineId', (string) $pipeline->id)
        ->call('addDeployPipelineHookFromPalette', 'shell', 'before_activate')
        ->assertDispatched('open-modal', 'pipeline-hook-form')
        ->assertSet('show_add_pipeline_hook_form', true)
        ->assertSet('hook_form_anchor_locked', true)
        ->assertSet('new_hook_kind', 'shell')
        ->assertSet('new_hook_anchor', 'before_activate')
        ->assertSet('new_hook_script', "echo ok\n");

    expect(SiteDeployHook::query()->where('pipeline_id', $pipeline->id)->count())->toBe(0);
});

test('addDeployPipelineHookFromPalette opens form for webhooks', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->call('addDeployPipelineHookFromPalette', 'webhook', 'after_clone')
        ->assertSet('show_add_pipeline_hook_form', true)
        ->assertSet('new_hook_kind', 'webhook')
        ->assertSet('new_hook_anchor', 'after_clone');
});

test('addDeployPipelineHookFromPalette opens form for hook after build step', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $step = app(SiteDeployPipelineManager::class)->addStep(
        $pipeline,
        SiteDeployStep::TYPE_CUSTOM,
        'npm run build',
        600,
    );

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->set('editingPipelineId', (string) $pipeline->id)
        ->call('addDeployPipelineHookFromPalette', 'webhook', 'after_step', (string) $step->id)
        ->assertSet('show_add_pipeline_hook_form', true)
        ->assertSet('hook_form_anchor_locked', true)
        ->assertSet('new_hook_anchor', 'after_step')
        ->assertSet('new_hook_anchor_step_id', (string) $step->id);
});

test('pipeline workspace can edit deploy hook', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $hook = SiteDeployHook::query()->create([
        'site_id' => $site->id,
        'pipeline_id' => $pipeline->id,
        'phase' => SiteDeployHook::ANCHOR_AFTER_CLONE,
        'hook_kind' => SiteDeployHook::KIND_SHELL,
        'anchor' => SiteDeployHook::ANCHOR_AFTER_CLONE,
        'script' => 'echo before',
        'sort_order' => 0,
        'timeout_seconds' => 900,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->set('editingPipelineId', (string) $pipeline->id)
        ->call('openEditPipelineHook', $hook->id)
        ->assertDispatched('open-modal', 'pipeline-hook-form')
        ->assertSet('editing_deploy_hook_id', $hook->id)
        ->assertSet('new_hook_script', 'echo before')
        ->set('new_hook_script', 'echo after')
        ->call('saveDeployPipelineHook')
        ->assertSet('show_add_pipeline_hook_form', false);

    expect($hook->fresh()->script)->toBe('echo after');
});

test('pipeline workspace can add shell hook on timeline', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    Livewire::actingAs($user)
        ->test(WorkspacePipeline::class, ['server' => $server, 'site' => $site])
        ->set('pipelineTab', 'steps')
        ->set('editingPipelineId', (string) $pipeline->id)
        ->call('openAddPipelineHookForm', 'shell', 'after_clone')
        ->assertDispatched('open-modal', 'pipeline-hook-form')
        ->set('new_hook_script', 'echo pipeline-hook')
        ->call('addDeployPipelineHook')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('site_deploy_hooks', [
        'site_id' => $site->id,
        'pipeline_id' => $pipeline->id,
        'hook_kind' => 'shell',
        'anchor' => 'after_clone',
        'script' => 'echo pipeline-hook',
    ]);
});
