<?php

declare(strict_types=1);

use App\Livewire\Backups\Databases as BackupsDatabases;
use App\Models\BackupConfiguration;
use App\Models\BackupSchedule;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function scheduleEditorContext(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $database = ServerDatabase::factory()->create([
        'server_id' => $server->id,
        'name' => 'app_production',
        'engine' => 'postgres',
    ]);

    return [$user, $org, $server, $database];
}

it('creates a schedule for an unscheduled database', function () {
    [$user, $org, $server, $database] = scheduleEditorContext();
    $destination = BackupConfiguration::factory()->forOrganization($org)->create();

    Livewire::actingAs($user)
        ->test(BackupsDatabases::class)
        ->call('openScheduleModal', BackupSchedule::TARGET_DATABASE, $database->id, $server->id)
        ->assertSet('showScheduleModal', true)
        ->assertSet('editing_schedule_id', null)
        ->set('scheduleForm.cadence', '0 */6 * * *')
        ->set('scheduleForm.backup_configuration_id', $destination->id)
        ->call('saveSchedule')
        ->assertHasNoErrors()
        ->assertSet('showScheduleModal', false);

    $schedule = BackupSchedule::query()->where('target_id', $database->id)->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->cron_expression)->toBe('0 */6 * * *');
    expect($schedule->backup_configuration_id)->toBe($destination->id);
    expect($schedule->is_active)->toBeTrue();
});

it('retimes an existing schedule and can change where it ships', function () {
    [$user, $org, $server, $database] = scheduleEditorContext();
    $first = BackupConfiguration::factory()->forOrganization($org)->create(['name' => 'Old bucket']);
    $second = BackupConfiguration::factory()->forOrganization($org)->create(['name' => 'New bucket']);

    $schedule = BackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => BackupSchedule::TARGET_DATABASE,
        'target_id' => $database->id,
        'backup_configuration_id' => $first->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(BackupsDatabases::class)
        ->call('editSchedule', $schedule->id)
        ->assertSet('scheduleForm.cadence', '0 3 * * *')
        ->assertSet('scheduleForm.backup_configuration_id', $first->id)
        ->set('scheduleForm.cadence', '0 * * * *')
        ->set('scheduleForm.backup_configuration_id', $second->id)
        ->call('saveSchedule')
        ->assertHasNoErrors();

    expect($schedule->fresh()->cron_expression)->toBe('0 * * * *');
    expect($schedule->fresh()->backup_configuration_id)->toBe($second->id);
});

it('opens a non preset expression on custom rather than silently retiming it', function () {
    [$user, $org, $server, $database] = scheduleEditorContext();

    $schedule = BackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => BackupSchedule::TARGET_DATABASE,
        'target_id' => $database->id,
        'cron_expression' => '*/15 * * * *',
        'is_active' => true,
    ]);

    // '*/15 * * * *' isn't in the preset list. If the form opened on a preset,
    // saving without touching the cadence would quietly change the schedule.
    Livewire::actingAs($user)
        ->test(BackupsDatabases::class)
        ->call('editSchedule', $schedule->id)
        ->assertSet('scheduleForm.cadence', 'custom')
        ->assertSet('scheduleForm.cron_expression', '*/15 * * * *')
        ->call('saveSchedule')
        ->assertHasNoErrors();

    expect($schedule->fresh()->cron_expression)->toBe('*/15 * * * *');
});

it('rejects an invalid cron expression', function () {
    [$user, $org, $server, $database] = scheduleEditorContext();

    Livewire::actingAs($user)
        ->test(BackupsDatabases::class)
        ->call('openScheduleModal', BackupSchedule::TARGET_DATABASE, $database->id, $server->id)
        ->set('scheduleForm.cadence', 'custom')
        ->set('scheduleForm.cron_expression', 'every tuesday please')
        ->call('saveSchedule')
        ->assertHasErrors('scheduleForm.cron_expression');

    expect(BackupSchedule::query()->where('target_id', $database->id)->count())->toBe(0);
});

it('refuses a destination from another organization', function () {
    [$user, $org, $server, $database] = scheduleEditorContext();
    $otherOrg = Organization::factory()->create();
    $foreign = BackupConfiguration::factory()->forOrganization($otherOrg)->create();

    // A guessed id must not become a live shipping target for someone else's bucket.
    Livewire::actingAs($user)
        ->test(BackupsDatabases::class)
        ->call('openScheduleModal', BackupSchedule::TARGET_DATABASE, $database->id, $server->id)
        ->set('scheduleForm.backup_configuration_id', $foreign->id)
        ->call('saveSchedule')
        ->assertHasErrors('scheduleForm.backup_configuration_id');

    expect(BackupSchedule::query()->where('target_id', $database->id)->count())->toBe(0);
});

it('deletes a schedule and says what that stops', function () {
    [$user, $org, $server, $database] = scheduleEditorContext();

    $schedule = BackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => BackupSchedule::TARGET_DATABASE,
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(BackupsDatabases::class)
        ->call('editSchedule', $schedule->id)
        ->call('deleteSchedule', $schedule->id)
        ->assertSet('showScheduleModal', false);

    expect(BackupSchedule::query()->find($schedule->id))->toBeNull();
});

it('lets a schedule be paused from the editor without losing its settings', function () {
    [$user, $org, $server, $database] = scheduleEditorContext();

    $schedule = BackupSchedule::create([
        'server_id' => $server->id,
        'target_type' => BackupSchedule::TARGET_DATABASE,
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(BackupsDatabases::class)
        ->call('editSchedule', $schedule->id)
        ->set('scheduleForm.is_active', false)
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $fresh = $schedule->fresh();
    expect($fresh->is_active)->toBeFalse();
    // Paused, not reset — the cadence survives.
    expect($fresh->cron_expression)->toBe('0 3 * * *');
});

it('derives server_id from the target rather than the caller', function () {
    [$user, $org, $server, $database] = scheduleEditorContext();

    // A second server the caller might pass by mistake. If server_id came from
    // the argument instead of the target, the schedule would point at a box the
    // database does not live on — and "Run now" would fail on it.
    $otherServer = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    Livewire::actingAs($user)
        ->test(BackupsDatabases::class)
        ->call('openScheduleModal', BackupSchedule::TARGET_DATABASE, $database->id, $otherServer->id)
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $schedule = BackupSchedule::query()->where('target_id', $database->id)->first();
    expect($schedule->server_id)->toBe($database->server_id);
    expect($schedule->server_id)->not->toBe($otherServer->id);
});

it('can run a schedule whose server_id has drifted from its target', function () {
    [$user, $org, $server, $database] = scheduleEditorContext();
    $otherServer = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    // Legacy rows exist with this drift — the scheduler runs them fine because
    // the dump executes on the database's server, so the UI must too.
    $schedule = BackupSchedule::create([
        'server_id' => $otherServer->id,
        'target_type' => BackupSchedule::TARGET_DATABASE,
        'target_id' => $database->id,
        'cron_expression' => '0 3 * * *',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(BackupsDatabases::class)
        ->call('runScheduleNow', $schedule->id);

    expect(\App\Models\ServerDatabaseBackup::query()->where('server_database_id', $database->id)->count())
        ->toBe(1);
});

it('creates a site-files schedule from the Files tab', function () {
    [$user, $org, $server] = scheduleEditorContext();
    $site = \App\Models\Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'marketing-site',
    ]);

    Livewire::actingAs($user)
        ->test(\App\Livewire\Backups\Files::class)
        ->call('openScheduleModal', BackupSchedule::TARGET_SITE_FILES, $site->id, $server->id)
        ->assertSet('showScheduleModal', true)
        ->set('scheduleForm.cadence', '0 3 * * 0')
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $schedule = BackupSchedule::query()->where('target_id', $site->id)->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->target_type)->toBe(BackupSchedule::TARGET_SITE_FILES);
    expect($schedule->cron_expression)->toBe('0 3 * * 0');
    // Same drift guard as databases: derived from the site, not the argument.
    expect($schedule->server_id)->toBe($site->server_id);
});

it('filters archive history and resets to the first page', function () {
    [$user, $org, $server] = scheduleEditorContext();
    $site = \App\Models\Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'shop',
    ]);

    \App\Modules\Backups\Models\SiteFileBackup::create([
        'site_id' => $site->id,
        'status' => \App\Modules\Backups\Models\SiteFileBackup::STATUS_FAILED,
        'error_message' => 'tar: Permission denied',
    ]);
    \App\Modules\Backups\Models\SiteFileBackup::create([
        'site_id' => $site->id,
        'status' => \App\Modules\Backups\Models\SiteFileBackup::STATUS_COMPLETED,
        'bytes' => 1024,
    ]);

    Livewire::actingAs($user)
        ->test(\App\Livewire\Backups\Files::class)
        ->set('runStatus', \App\Modules\Backups\Models\SiteFileBackup::STATUS_FAILED)
        // The explainer turns "Permission denied" into something actionable.
        ->assertSee('refused access', false)
        ->assertDontSee('COMPLETED', false);
});
