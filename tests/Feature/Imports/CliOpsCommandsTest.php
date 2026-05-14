<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CliOpsCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function seedMigration(string $source = 'ploi', string $status = 'staging'): ImportServerMigration
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create(['name' => 'Acme Co']);
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => $source,
        ]);
        $target = Server::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => 'dply-target-01',
        ]);
        $migration = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => $source,
            'source_server_id' => 42,
            'target_server_id' => $target->id,
            'status' => $status,
            'started_at' => now()->subHour(),
        ]);
        $child = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => $source,
            'source_site_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'status' => ImportSiteMigration::STATUS_STAGING,
            'source_snapshot' => [],
        ]);
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'sequence' => 1,
            'step_key' => ImportMigrationStep::KEY_PUSH_SSH_KEY,
            'status' => ImportMigrationStep::STATUS_SUCCEEDED,
            'finished_at' => now()->subMinutes(30),
        ]);
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $child->id,
            'sequence' => 5,
            'step_key' => ImportMigrationStep::KEY_CLONE_REPO,
            'status' => ImportMigrationStep::STATUS_FAILED,
            'error_message' => 'git clone failed: repository not found',
            'finished_at' => now()->subMinutes(10),
        ]);
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $child->id,
            'sequence' => 6,
            'step_key' => ImportMigrationStep::KEY_COPY_ENV,
            'status' => ImportMigrationStep::STATUS_PENDING,
        ]);

        return $migration;
    }

    public function test_list_command_renders_each_migration_with_step_counts(): void
    {
        $this->seedMigration(source: 'ploi');
        $this->seedMigration(source: 'forge');

        \Illuminate\Support\Facades\Artisan::call('dply:imports:list');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('ploi', $output);
        $this->assertStringContainsString('forge', $output);
        $this->assertStringContainsString('1/1/1', $output); // 1 succeeded, 1 failed, 1 pending
        $this->assertStringContainsString('Acme Co', $output);
        $this->assertStringContainsString('dply-target-01', $output);
    }

    public function test_list_command_filters_by_source(): void
    {
        $this->seedMigration(source: 'ploi');
        $this->seedMigration(source: 'forge');

        \Illuminate\Support\Facades\Artisan::call('dply:imports:list', ['--source' => 'forge']);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('forge', $output);
        // Body rows shouldn't include the ploi-sourced migration; the header
        // 'Source' column doesn't substring-match 'ploi', so this check is safe.
        $this->assertStringNotContainsString('| ploi', $output);
    }

    public function test_list_command_filters_to_active_when_flag_set(): void
    {
        $this->seedMigration(status: 'completed');
        $this->seedMigration(status: 'staging');

        \Illuminate\Support\Facades\Artisan::call('dply:imports:list', ['--active' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('staging', $output);
        $this->assertStringNotContainsString('completed', $output);
    }

    public function test_list_command_handles_empty_result(): void
    {
        \Illuminate\Support\Facades\Artisan::call('dply:imports:list');
        $this->assertStringContainsString('No matching migrations.', \Illuminate\Support\Facades\Artisan::output());
    }

    public function test_show_command_renders_full_step_plan_with_failure_message(): void
    {
        $migration = $this->seedMigration();

        // Capture the full output buffer once via Artisan::call and grep the result;
        // expectsOutputToContain is order-sensitive (each call advances a cursor)
        // which doesn't fit a multi-line table-like rendering.
        \Illuminate\Support\Facades\Artisan::call('dply:imports:show', ['migration' => $migration->id]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('Migration '.$migration->id, $output);
        $this->assertStringContainsString('Source:', $output);
        $this->assertStringContainsString('ploi server 42', $output);
        $this->assertStringContainsString('Server-level steps', $output);
        $this->assertStringContainsString('push_ssh_key', $output);
        $this->assertStringContainsString('Site: app.example.com', $output);
        $this->assertStringContainsString('clone_repo', $output);
        $this->assertStringContainsString('git clone failed: repository not found', $output);
    }

    public function test_show_command_returns_failure_when_migration_not_found(): void
    {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('dply:imports:show', ['migration' => '01jfakeulid000000000000000']);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Migration not found.', \Illuminate\Support\Facades\Artisan::output());
    }
}
