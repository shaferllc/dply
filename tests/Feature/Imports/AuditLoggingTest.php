<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\AuditLoggingTest;

use App\Livewire\Imports\Ploi\MigrationProgress;
use App\Livewire\Servers\Create\StepReview;
use App\Models\AuditLog;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: ImportServerMigration, 3: ImportSiteMigration}
 */
function seedMigration(string $status = ImportServerMigration::STATUS_STAGING): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

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
        'status' => $status,
        'ssh_key_source_id' => 9001,
        'ssh_key_pushed_at' => now()->subHour(),
    ]);
    $site = ImportSiteMigration::create([
        'import_server_migration_id' => $migration->id,
        'source' => 'ploi',
        'source_site_id' => 100,
        'domain' => 'app.example.com',
        'site_type' => 'laravel',
        'status' => ImportSiteMigration::STATUS_READY_FOR_CUTOVER,
        'source_snapshot' => [],
    ]);

    return [$user, $org, $migration, $site];
}
test('abort writes audit log', function () {
    Bus::fake();
    [$user, $org, $migration] = seedMigration();
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 99,
        'step_key' => ImportMigrationStep::KEY_REVOKE_SSH_KEY,
        'status' => ImportMigrationStep::STATUS_PENDING,
    ]);

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('abortMigration');

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'action' => 'import.migration.aborted',
        'subject_type' => ImportServerMigration::class,
        'subject_id' => $migration->id,
    ]);
});
test('begin cutover writes audit log', function () {
    Bus::fake();
    [$user, $org, $migration, $site] = seedMigration();
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $site->id,
        'sequence' => 50,
        'step_key' => ImportMigrationStep::KEY_CUTOVER_MAINTENANCE_ON,
        'status' => ImportMigrationStep::STATUS_PENDING,
    ]);

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('beginCutover', $site->id);

    $log = AuditLog::query()
        ->where('action', 'import.migration.cutover_begun')
        ->where('subject_id', $migration->id)
        ->first();
    expect($log)->not->toBeNull();
    expect($log->new_values['domain'])->toBe('app.example.com');
});
test('rollback writes audit log', function () {
    Http::fake();
    [$user, $org, $migration, $site] = seedMigration();
    $site->status = ImportSiteMigration::STATUS_CUTOVER_FAILED;
    $site->save();

    // Synthesize a successful DNS swap step to give rollback a target id.
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $site->id,
        'sequence' => 60,
        'step_key' => ImportMigrationStep::KEY_CUTOVER_DNS_SWAP,
        'status' => ImportMigrationStep::STATUS_SUCCEEDED,
        'result_data' => ['zone' => 'example.com', 'record_id' => 7777],
    ]);

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('rollbackCutoverDns', $site->id);

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $org->id,
        'action' => 'import.migration.cutover_rolled_back',
        'subject_id' => $migration->id,
    ]);
});
test('retry writes audit log', function () {
    Bus::fake();
    [$user, $org, $migration, $site] = seedMigration();
    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $site->id,
        'sequence' => 10,
        'step_key' => ImportMigrationStep::KEY_CLONE_REPO,
        'status' => ImportMigrationStep::STATUS_FAILED,
        'attempts' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('retryFailedStep', $step->id);

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $org->id,
        'action' => 'import.migration.step_retried',
        'subject_id' => $migration->id,
    ]);
});
test('skip writes audit log', function () {
    Bus::fake();
    [$user, $org, $migration, $site] = seedMigration();
    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $site->id,
        'sequence' => 14,
        'step_key' => ImportMigrationStep::KEY_RECREATE_CRONS,
        'status' => ImportMigrationStep::STATUS_FAILED,
    ]);

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('skipFailedStep', $step->id);

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $org->id,
        'action' => 'import.migration.step_skipped',
        'subject_id' => $migration->id,
    ]);
});
test('auto expire writes audit log', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/keys/9001' => Http::response('', 204),
    ]);
    [, $org, $migration] = seedMigration();
    $migration->ssh_key_pushed_at = now()->subDays(10);
    $migration->save();
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => ImportMigrationStep::KEY_PUSH_SSH_KEY,
        'status' => ImportMigrationStep::STATUS_SUCCEEDED,
        'finished_at' => now()->subDays(10),
    ]);

    $this->artisan('dply:imports:expire-paused')->assertSuccessful();

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $org->id,
        'action' => 'import.migration.expired',
        'subject_id' => $migration->id,
    ]);
});
test('kickoff writes audit log with site count', function () {
    Bus::fake();
    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
    ]);
    $ploiServer = PloiServer::create([
        'provider_credential_id' => $credential->id,
        'source_id' => 42,
        'name' => 'prod-web-01',
        'ip_address' => '203.0.113.10',
        'provider_label' => 'digital-ocean',
        'server_type' => 's-2vcpu-4gb',
        'php_versions' => ['8.3'],
        'status' => 'active',
        'last_synced_at' => now(),
        'removed_from_source' => false,
        'source_snapshot' => null,
    ]);
    PloiSite::create([
        'ploi_server_id' => $ploiServer->id,
        'source_id' => 100,
        'domain' => 'app.example.com',
        'site_type' => 'laravel',
        'php_version' => '8.3',
        'repository_url' => 'git@github.com:acme/app.git',
        'repository_branch' => 'main',
        'web_directory' => '/public',
        'status' => 'installed',
        'removed_from_source' => false,
        'source_snapshot' => ['repository' => 'acme/app'],
    ]);

    $stepReview = $this->app->make(StepReview::class);
    $target = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $ref = new \ReflectionMethod($stepReview, 'kickOffPloiMigration');
    $ref->setAccessible(true);
    $migration = $ref->invoke($stepReview, $ploiServer->id, $target, $user, null);

    expect($migration)->not->toBeNull();
    $log = AuditLog::query()
        ->where('action', 'import.migration.started')
        ->where('subject_id', $migration->id)
        ->first();
    expect($log)->not->toBeNull();
    expect($log->new_values['source'])->toBe('ploi');
    expect($log->new_values['site_count'])->toBe(1);
});
