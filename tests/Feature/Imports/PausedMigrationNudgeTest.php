<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Q17 trust-window completion: between 72h and 168h of paused inactivity,
 * fire a single import.migration.paused_nudge notification so the user
 * knows the ephemeral key will be auto-revoked at 168h.
 */
class PausedMigrationNudgeTest extends TestCase
{
    use RefreshDatabase;

    protected function seedMigration(Carbon $latestActivity): ImportServerMigration
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

    public function test_emits_nudge_when_between_72h_and_168h(): void
    {
        $migration = $this->seedMigration(Carbon::now()->subDays(4)); // 96h

        $this->artisan('dply:imports:expire-paused')
            ->assertSuccessful();

        $this->assertDatabaseHas('notification_events', [
            'event_key' => 'import.migration.paused_nudge',
            'subject_type' => ImportServerMigration::class,
            'subject_id' => $migration->id,
        ]);
        $event = NotificationEvent::query()->where('event_key', 'import.migration.paused_nudge')->first();
        $this->assertTrue($event->supports_email);
        $this->assertSame('warning', $event->severity);

        $migration->refresh();
        $this->assertNotNull($migration->paused_nudge_sent_at);
        $this->assertSame(ImportServerMigration::STATUS_STAGING, $migration->status, 'Still active, not yet expired');
    }

    public function test_does_not_emit_nudge_inside_72h_window(): void
    {
        $migration = $this->seedMigration(Carbon::now()->subHours(48)); // 48h

        $this->artisan('dply:imports:expire-paused')
            ->assertSuccessful();

        $this->assertDatabaseMissing('notification_events', [
            'event_key' => 'import.migration.paused_nudge',
            'subject_id' => $migration->id,
        ]);
        $this->assertNull($migration->fresh()->paused_nudge_sent_at);
    }

    public function test_does_not_emit_nudge_twice_for_same_migration(): void
    {
        $migration = $this->seedMigration(Carbon::now()->subDays(4));

        $this->artisan('dply:imports:expire-paused')->assertSuccessful();
        $this->artisan('dply:imports:expire-paused')->assertSuccessful();
        $this->artisan('dply:imports:expire-paused')->assertSuccessful();

        $this->assertSame(1, NotificationEvent::query()
            ->where('event_key', 'import.migration.paused_nudge')
            ->where('subject_id', $migration->id)
            ->count());
    }

    public function test_skips_nudge_when_past_expiry_and_expires_instead(): void
    {
        // 10 days paused — past expiry threshold (168h); should expire, not nudge.
        $migration = $this->seedMigration(Carbon::now()->subDays(10));

        $this->artisan('dply:imports:expire-paused')
            ->assertSuccessful();

        $this->assertSame(ImportServerMigration::STATUS_EXPIRED, $migration->fresh()->status);
        $this->assertDatabaseMissing('notification_events', [
            'event_key' => 'import.migration.paused_nudge',
            'subject_id' => $migration->id,
        ]);
    }

    public function test_dry_run_reports_without_publishing(): void
    {
        $migration = $this->seedMigration(Carbon::now()->subDays(4));

        $this->artisan('dply:imports:expire-paused', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] nudging migration')
            ->assertSuccessful();

        $this->assertDatabaseMissing('notification_events', [
            'event_key' => 'import.migration.paused_nudge',
            'subject_id' => $migration->id,
        ]);
        $this->assertNull($migration->fresh()->paused_nudge_sent_at);
    }
}
