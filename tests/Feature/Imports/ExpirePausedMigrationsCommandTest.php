<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\ExpirePausedMigrationsCommandTest;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function seedMigration(Carbon $latestActivity, int $sourceKeyId = 9001, string $status = ImportServerMigration::STATUS_STAGING): ImportServerMigration
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_token'],
    ]);
    $migration = ImportServerMigration::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider_credential_id' => $credential->id,
        'source' => 'ploi',
        'source_server_id' => 42,
        'status' => $status,
        'ssh_key_source_id' => $sourceKeyId,
        'ssh_key_pushed_at' => $latestActivity->copy()->subHour(),
    ]);

    // Synthesize a step finished at $latestActivity so the command's stale-check picks it up.
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => ImportMigrationStep::KEY_PUSH_SSH_KEY,
        'status' => ImportMigrationStep::STATUS_SUCCEEDED,
        'finished_at' => $latestActivity,
    ]);
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 2,
        'step_key' => ImportMigrationStep::KEY_ELIGIBILITY_SCAN,
        'status' => ImportMigrationStep::STATUS_PENDING,
    ]);

    return $migration;
}
test('expires migration paused beyond threshold and revokes key', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/keys/9001' => Http::response('', 204),
    ]);
    $stale = Carbon::now()->subDays(10);
    $migration = seedMigration($stale);

    $this->artisan('dply:imports:expire-paused')
        ->assertSuccessful();

    $migration->refresh();
    expect($migration->status)->toBe(ImportServerMigration::STATUS_EXPIRED);
    expect($migration->ssh_key_revoked_at)->not->toBeNull();
    $this->assertStringContainsString('168h', $migration->failure_summary);

    // Pending steps cascade-skipped.
    expect(ImportMigrationStep::query()
        ->where('import_server_migration_id', $migration->id)
        ->where('status', ImportMigrationStep::STATUS_PENDING)
        ->count())->toBe(0);

    Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
        && str_ends_with($req->url(), '/servers/42/keys/9001'));
});
test('does not expire migration inside threshold', function () {
    Http::fake();
    $fresh = Carbon::now()->subHours(48);
    $migration = seedMigration($fresh);

    $this->artisan('dply:imports:expire-paused')
        ->assertSuccessful();

    $migration->refresh();
    expect($migration->status)->toBe(ImportServerMigration::STATUS_STAGING);
    expect($migration->ssh_key_revoked_at)->toBeNull();
    Http::assertNothingSent();
});
test('does not expire terminal migrations', function () {
    Http::fake();
    $stale = Carbon::now()->subDays(30);
    $migration = seedMigration($stale, status: ImportServerMigration::STATUS_COMPLETED);

    $this->artisan('dply:imports:expire-paused')
        ->assertSuccessful();

    $migration->refresh();
    expect($migration->status)->toBe(ImportServerMigration::STATUS_COMPLETED);
    Http::assertNothingSent();
});
test('dry run reports but does not mutate', function () {
    Http::fake();
    $stale = Carbon::now()->subDays(10);
    $migration = seedMigration($stale);

    $this->artisan('dply:imports:expire-paused', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();

    $migration->refresh();
    expect($migration->status)->toBe(ImportServerMigration::STATUS_STAGING);
    expect($migration->ssh_key_revoked_at)->toBeNull();
    Http::assertNothingSent();
});
test('succeeds even when remote revoke fails', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/keys/9001' => Http::response('not found', 404),
    ]);
    $stale = Carbon::now()->subDays(10);
    $migration = seedMigration($stale);

    $this->artisan('dply:imports:expire-paused')
        ->assertSuccessful();

    $migration->refresh();
    expect($migration->status)->toBe(ImportServerMigration::STATUS_EXPIRED, 'Status flips to expired regardless of remote revoke failure');
});
