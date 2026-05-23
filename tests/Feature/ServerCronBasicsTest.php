<?php

namespace Tests\Feature\ServerCronBasicsTest;

use App\Livewire\Servers\WorkspaceCron;
use App\Models\Organization;
use App\Models\OrganizationCronJobTemplate;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\User;
use App\Services\Servers\ServerCronSynchronizer;
use App\Services\Servers\ServerCrontabReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function readyServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);
}

/**
 * Create a deployer-role user attached to the same org as $owner. Caller is
 * responsible for `actingAs($deployer)` afterwards — the session is global
 * for the test process, so the current-org setup from $owner carries over.
 */
function deployerInSameOrg(User $owner): User
{
    $org = $owner->currentOrganization();
    $deployer = User::factory()->create();
    $org->users()->attach($deployer->id, ['role' => 'deployer']);

    return $deployer;
}

test('cron page uses basics first layout', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    $this->actingAs($user)
        ->get(route('servers.cron', $server))
        ->assertOk()
        ->assertSee('Schedule commands in the Dply-managed crontab block for this server.')
        ->assertSee('Scheduled jobs')
        ->assertDontSee('Troubleshooting')
        // Tab strip: every section is reachable, including the org-level ones.
        ->assertSee('cron-tab-jobs', false)
        ->assertSee('cron-tab-history', false)
        ->assertSee('cron-tab-inspect', false)
        ->assertSee('cron-tab-templates', false)
        ->assertSee('cron-tab-maintenance', false);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->call('setCronWorkspaceTab', 'history')
        ->assertSee('Recent run history')
        ->call('setCronWorkspaceTab', 'inspect')
        ->assertSee('Inspect crontab');
});

test('user can add basic cron job', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->set('new_cron_command', 'php /var/www/current/artisan schedule:run')
        ->set('new_cron_user', 'deploy')
        ->set('frequency_preset', 'hourly')
        ->set('new_cron_expression', '0 * * * *')
        ->set('new_description', 'Laravel scheduler')
        ->call('saveCronJob')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('server_cron_jobs', [
        'server_id' => $server->id,
        'command' => 'php /var/www/current/artisan schedule:run',
        'user' => 'deploy',
        'cron_expression' => '0 * * * *',
        'description' => 'Laravel scheduler',
    ]);
});

test('user can pause and delete cron job', function () {
    $user = userWithOrganization();
    $server = readyServer($user);
    $job = ServerCronJob::query()->create([
        'server_id' => $server->id,
        'cron_expression' => '* * * * *',
        'command' => 'php artisan schedule:run',
        'user' => 'deploy',
        'enabled' => true,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->call('toggleCronJob', (string) $job->id);

    expect($job->fresh()->enabled)->toBeFalse();

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->call('deleteCronJob', (string) $job->id);

    $this->assertDatabaseMissing('server_cron_jobs', [
        'id' => $job->id,
    ]);
});

test('user can sync crontab from basics page', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    $synchronizer = Mockery::mock(ServerCronSynchronizer::class);
    $synchronizer->shouldReceive('invalidExpressions')->andReturn([]);
    $synchronizer->shouldReceive('sync')
        ->once()
        ->andReturn("installed\nDPLY_CRON_EXIT:0");
    $this->app->instance(ServerCronSynchronizer::class, $synchronizer);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->call('syncCronJobs')
        ->assertDispatched('notify', message: __('Crontab sync finished — see the banner for the host output.'), type: 'success')
        ->assertSet('panel_event_status', 'completed')
        ->assertSet('panel_event_message', __('Crontab synced to server.'));
});

test('apply cron bundle inserts panel rows and emits panel event', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->call('applyCronBundle', 'certbot_renew')
        ->assertSet('panel_event_status', 'completed')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('server_cron_jobs', [
        'server_id' => $server->id,
        'cron_expression' => '0 3 * * *',
        'user' => 'root',
        'is_synced' => false,
        'enabled' => true,
    ]);
});

test('apply cron bundle skips duplicates', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    ServerCronJob::query()->create([
        'server_id' => $server->id,
        'cron_expression' => '0 3 * * *',
        'command' => 'certbot renew --quiet --deploy-hook "systemctl reload nginx"',
        'user' => 'root',
        'enabled' => true,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->call('applyCronBundle', 'certbot_renew')
        ->assertDispatched('notify', type: 'warning');

    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(1);
});

test('apply cron bundle unknown key warns', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->call('applyCronBundle', 'does_not_exist')
        ->assertDispatched('notify', type: 'error');

    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(0);
});

test('loading crontab populates inspect body', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    $reader = Mockery::mock(ServerCrontabReader::class);
    $reader->shouldReceive('readForUser')
        ->once()
        ->andReturn([
            'body' => '* * * * * php artisan schedule:run',
            'exit_code' => 0,
        ]);
    $this->app->instance(ServerCrontabReader::class, $reader);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->set('inspect_crontab_user', 'deploy')
        ->call('loadInspectCrontab')
        ->assertSet('inspect_crontab_body', '* * * * * php artisan schedule:run')
        ->assertSet('inspect_crontab_exit_code', 0);
});

test('set cron workspace tab accepts known values', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->assertSet('cron_workspace_tab', 'jobs')
        ->call('setCronWorkspaceTab', 'history')
        ->assertSet('cron_workspace_tab', 'history')
        ->call('setCronWorkspaceTab', 'templates')
        ->assertSet('cron_workspace_tab', 'templates')
        // Unknown value snaps back to the default jobs tab.
        ->call('setCronWorkspaceTab', 'bogus')
        ->assertSet('cron_workspace_tab', 'jobs');
});

test('tab query param deep links to inspect', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    // The #[Url(as: 'tab')] attribute hydrates cron_workspace_tab from
    // ?tab=…, so an inbound link can land directly on Inspect.
    Livewire::withQueryParams(['tab' => 'inspect'])
        ->actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->assertSet('cron_workspace_tab', 'inspect');
});

test('non admin does not see maintenance tab link', function () {
    $owner = userWithOrganization();
    $server = readyServer($owner);
    $deployer = deployerInSameOrg($owner);

    $this->actingAs($deployer)
        ->get(route('servers.cron', $server))
        ->assertOk()
        ->assertSee('cron-tab-jobs', false)
        ->assertSee('cron-tab-templates', false)
        ->assertDontSee('cron-tab-maintenance', false);
});

test('non admin navigating to maintenance tab snaps to jobs', function () {
    $owner = userWithOrganization();
    $server = readyServer($owner);
    $deployer = deployerInSameOrg($owner);

    Livewire::actingAs($deployer)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->set('cron_workspace_tab', 'maintenance')
        ->assertSet('cron_workspace_tab', 'jobs');
});

test('apply org cron template loads form and opens modal', function () {
    $user = userWithOrganization();
    $server = readyServer($user);
    $tpl = OrganizationCronJobTemplate::query()->create([
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'Laravel scheduler',
        'cron_expression' => '* * * * *',
        'command' => 'php artisan schedule:run',
        'user' => 'deploy',
        'description' => 'Laravel scheduler',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->call('applyOrgCronTemplate', (string) $tpl->id)
        ->assertSet('new_cron_expression', '* * * * *')
        ->assertSet('new_cron_command', 'php artisan schedule:run')
        ->assertSet('new_cron_user', 'deploy')
        ->assertSet('new_description', 'Laravel scheduler')
        ->assertSet('cron_workspace_tab', 'jobs')
        ->assertDispatched('open-modal', 'add-cron-job-modal');
});

test('admin can save org cron template', function () {
    $user = userWithOrganization();
    $server = readyServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->set('new_cron_expression', '0 2 * * *')
        ->set('new_cron_command', 'php artisan backups:run')
        ->set('new_cron_user', 'deploy')
        ->set('new_description', 'Nightly backups')
        ->set('template_save_name', 'Nightly backups')
        ->call('saveOrgCronTemplate')
        ->assertHasNoErrors()
        ->assertSet('template_save_name', null);

    $this->assertDatabaseHas('organization_cron_job_templates', [
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'Nightly backups',
        'cron_expression' => '0 2 * * *',
        'command' => 'php artisan backups:run',
        'user' => 'deploy',
    ]);
});

test('admin can delete org cron template', function () {
    $user = userWithOrganization();
    $server = readyServer($user);
    $tpl = OrganizationCronJobTemplate::query()->create([
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'doomed',
        'cron_expression' => '* * * * *',
        'command' => 'true',
        'user' => 'deploy',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->call('deleteOrgCronTemplate', (string) $tpl->id);

    $this->assertDatabaseMissing('organization_cron_job_templates', ['id' => $tpl->id]);
});

test('deployer cannot save org cron template', function () {
    $owner = userWithOrganization();
    $server = readyServer($owner);
    $deployer = deployerInSameOrg($owner);

    Livewire::actingAs($deployer)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->set('new_cron_expression', '0 2 * * *')
        ->set('new_cron_command', 'php artisan backups:run')
        ->set('new_cron_user', 'deploy')
        ->set('template_save_name', 'should-not-save')
        ->call('saveOrgCronTemplate')
        ->assertForbidden();

    $this->assertDatabaseMissing('organization_cron_job_templates', [
        'name' => 'should-not-save',
    ]);
});

test('admin can save org cron maintenance window', function () {
    $user = userWithOrganization();
    $server = readyServer($user);
    $org = $user->currentOrganization();

    $until = now()->addHours(3)->format('Y-m-d\TH:i');

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->set('org_maintenance_until_local', $until)
        ->set('org_maintenance_note', 'cluster upgrade in progress')
        ->call('saveOrgCronMaintenance')
        ->assertHasNoErrors();

    $org->refresh();
    expect($org->cron_maintenance_until)->not->toBeNull();
    expect($org->cron_maintenance_note)->toBe('cluster upgrade in progress');
});

test('admin can clear org cron maintenance window', function () {
    $user = userWithOrganization();
    $server = readyServer($user);
    $org = $user->currentOrganization();
    $org->update([
        'cron_maintenance_until' => now()->addDay(),
        'cron_maintenance_note' => 'paused',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->call('clearOrgCronMaintenance')
        ->assertSet('org_maintenance_until_local', null)
        ->assertSet('org_maintenance_note', '');

    $org->refresh();
    expect($org->cron_maintenance_until)->toBeNull();
    expect($org->cron_maintenance_note)->toBeNull();
});

test('deployer cannot save org cron maintenance window', function () {
    $owner = userWithOrganization();
    $server = readyServer($owner);
    $deployer = deployerInSameOrg($owner);

    Livewire::actingAs($deployer)
        ->test(WorkspaceCron::class, ['server' => $server])
        ->set('org_maintenance_until_local', now()->addHour()->format('Y-m-d\TH:i'))
        ->call('saveOrgCronMaintenance')
        ->assertForbidden();

    expect($owner->currentOrganization()->fresh()->cron_maintenance_until)->toBeNull();
});
