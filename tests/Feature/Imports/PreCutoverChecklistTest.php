<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-cutover checklist (Q4 design) — when an ImportSiteMigration reaches
 * READY_FOR_CUTOVER, the migration progress page should render a verification
 * panel BEFORE the Begin cutover button, summarising what was verified during
 * staging.
 */
class PreCutoverChecklistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, array<string, mixed>>  $stepResultData  step_key → result_data overrides
     */
    protected function seedReadyForCutover(User $user, array $stepResultData = []): ImportServerMigration
    {
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
            'status' => ImportServerMigration::STATUS_STAGING,
        ]);
        $site = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => 'ploi',
            'source_site_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'status' => ImportSiteMigration::STATUS_READY_FOR_CUTOVER,
            'source_snapshot' => [],
            'ssl_strategy' => ImportSiteMigration::SSL_CLEAN,
        ]);

        $defaults = [
            'create_target_site' => ['site_id' => '01krfaketargetid'],
            'clone_repo' => ['head' => 'abcdef1234567890abcdef1234567890abcdef12', 'site_root' => '/home/acme/acme'],
            'copy_env' => ['bytes' => 1234],
            'dump_database' => ['bytes' => 8123456, 'database' => 'acme_db'],
            'restore_database' => ['target_database' => 'acme'],
            'recreate_crons' => ['crons_created' => 2],
            'recreate_daemons' => ['workers_created' => 1, 'warnings' => []],
            'recreate_scheduler' => ['scheduler_created' => true],
            'setup_ssl' => ['strategy' => 'clean', 'detail' => 'queued for DNS-01 issuance'],
        ];
        $resolved = array_replace_recursive($defaults, $stepResultData);

        $seq = 10;
        foreach ($resolved as $key => $resultData) {
            ImportMigrationStep::create([
                'import_server_migration_id' => $migration->id,
                'import_site_migration_id' => $site->id,
                'sequence' => $seq++,
                'step_key' => $key,
                'status' => ImportMigrationStep::STATUS_SUCCEEDED,
                'result_data' => $resultData,
                'started_at' => now()->subMinutes(20),
                'finished_at' => now()->subMinutes(15),
            ]);
        }
        // Add a pending cutover step so beginCutover finds something to dispatch.
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $site->id,
            'sequence' => 50,
            'step_key' => ImportMigrationStep::KEY_CUTOVER_MAINTENANCE_ON,
            'status' => ImportMigrationStep::STATUS_PENDING,
        ]);

        return $migration;
    }

    public function test_progress_page_renders_pre_cutover_checklist_when_site_ready(): void
    {
        $user = User::factory()->create();
        $migration = $this->seedReadyForCutover($user);

        $response = $this->actingAs($user)->get(route('imports.ploi.migration.progress', $migration));

        $response->assertOk()
            ->assertSee('Pre-cutover verification')
            ->assertSee('dply site provisioned')
            ->assertSee('Code cloned')
            ->assertSee('Environment copied')
            ->assertSee('Database dumped')
            ->assertSee('Database restored on dply')
            ->assertSee('2 crons recreated')
            ->assertSee('1 worker recreated')
            ->assertSee('Laravel scheduler recreated')
            ->assertSee('SSL strategy:')
            ->assertSee('clean');
    }

    public function test_checklist_shows_db_size_in_mb(): void
    {
        $user = User::factory()->create();
        $migration = $this->seedReadyForCutover($user, [
            'dump_database' => ['bytes' => 5_242_880, 'database' => 'acme_db'], // 5.0 MB
        ]);

        $response = $this->actingAs($user)->get(route('imports.ploi.migration.progress', $migration));

        $response->assertOk()->assertSee('5.0 MB');
    }

    public function test_checklist_renders_skipped_db_correctly(): void
    {
        $user = User::factory()->create();
        $migration = $this->seedReadyForCutover($user);
        // Override the dump_database step to STATUS_SKIPPED.
        $dumpStep = $migration->steps()->where('step_key', 'dump_database')->first();
        $dumpStep->status = ImportMigrationStep::STATUS_SKIPPED;
        $dumpStep->result_data = ['reason' => 'no_database_on_source_site'];
        $dumpStep->save();

        $response = $this->actingAs($user)->get(route('imports.ploi.migration.progress', $migration));

        $response->assertOk()->assertSee('No database on source site');
    }

    public function test_checklist_calls_out_gap_ssl_with_warning(): void
    {
        $user = User::factory()->create();
        $migration = $this->seedReadyForCutover($user, [
            'setup_ssl' => ['strategy' => 'gap', 'note' => 'No DNS automation and no usable LE cert on Ploi; HTTPS issuance happens immediately after DNS swap (~30–120s gap).'],
        ]);
        $site = $migration->siteMigrations()->first();
        $site->ssl_strategy = ImportSiteMigration::SSL_GAP;
        $site->save();

        $response = $this->actingAs($user)->get(route('imports.ploi.migration.progress', $migration));

        $response->assertOk()
            ->assertSee('SSL strategy:')
            ->assertSee('gap')
            ->assertSee('review the amber warnings');
    }

    public function test_checklist_omitted_when_site_not_ready_for_cutover(): void
    {
        $user = User::factory()->create();
        $migration = $this->seedReadyForCutover($user);
        $site = $migration->siteMigrations()->first();
        $site->status = ImportSiteMigration::STATUS_STAGING;
        $site->save();

        $response = $this->actingAs($user)->get(route('imports.ploi.migration.progress', $migration));

        $response->assertOk()->assertDontSee('Pre-cutover verification');
    }
}
