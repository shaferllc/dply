<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\PausedMigrationNudgeTest;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function seedMigration(Carbon $latestActivity): ImportServerMigration
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
    ]);
    $migration = ImportServerMigration::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider_credential_id' => $credential->id,
        'source' => 'ploi',
        'source_server_id' => 42,
        'status' => ImportServerMigration::STATUS_STAGING,
        'ssh_key_source_id' => 9001,
        'ssh_key_pushed_at' => $latestActivity->copy()->subHour(),
    ]);
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => ImportMigrationStep::KEY_PUSH_SSH_KEY,
        'status' => ImportMigrationStep::STATUS_SUCCEEDED,
        'finished_at' => $latestActivity,
    ]);

    return $migration;
}
test('emits nudge when between 72h and 168h', function () {
    $migration = seedMigration(Carbon::now()->subDays(4));

    // 96h
    $this->artisan('dply:imports:expire-paused')
        ->assertSuccessful();

    $this->assertDatabaseHas('notification_events', [
        'event_key' => 'import.migration.paused_nudge',
        'subject_type' => ImportServerMigration::class,
        'subject_id' => $migration->id,
    ]);
    $event = NotificationEvent::query()->where('event_key', 'import.migration.paused_nudge')->first();
    expect($event->supports_email)->toBeTrue();
    expect($event->severity)->toBe('warning');

    $migration->refresh();
    expect($migration->paused_nudge_sent_at)->not->toBeNull();
    expect($migration->status)->toBe(ImportServerMigration::STATUS_STAGING, 'Still active, not yet expired');
});
test('does not emit nudge inside 72h window', function () {
    $migration = seedMigration(Carbon::now()->subHours(48));

    // 48h
    $this->artisan('dply:imports:expire-paused')
        ->assertSuccessful();

    $this->assertDatabaseMissing('notification_events', [
        'event_key' => 'import.migration.paused_nudge',
        'subject_id' => $migration->id,
    ]);
    expect($migration->fresh()->paused_nudge_sent_at)->toBeNull();
});
test('does not emit nudge twice for same migration', function () {
    $migration = seedMigration(Carbon::now()->subDays(4));

    $this->artisan('dply:imports:expire-paused')->assertSuccessful();
    $this->artisan('dply:imports:expire-paused')->assertSuccessful();
    $this->artisan('dply:imports:expire-paused')->assertSuccessful();

    expect(NotificationEvent::query()
        ->where('event_key', 'import.migration.paused_nudge')
        ->where('subject_id', $migration->id)
        ->count())->toBe(1);
});
test('skips nudge when past expiry and expires instead', function () {
    // 10 days paused — past expiry threshold (168h); should expire, not nudge.
    $migration = seedMigration(Carbon::now()->subDays(10));

    $this->artisan('dply:imports:expire-paused')
        ->assertSuccessful();

    expect($migration->fresh()->status)->toBe(ImportServerMigration::STATUS_EXPIRED);
    $this->assertDatabaseMissing('notification_events', [
        'event_key' => 'import.migration.paused_nudge',
        'subject_id' => $migration->id,
    ]);
});
test('dry run reports without publishing', function () {
    $migration = seedMigration(Carbon::now()->subDays(4));

    $this->artisan('dply:imports:expire-paused', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run] nudging migration')
        ->assertSuccessful();

    $this->assertDatabaseMissing('notification_events', [
        'event_key' => 'import.migration.paused_nudge',
        'subject_id' => $migration->id,
    ]);
    expect($migration->fresh()->paused_nudge_sent_at)->toBeNull();
});
