<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Services\Imports\MigrationPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationPlannerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ProviderCredential, 1: PloiServer, 2: list<PloiSite>, 3: User, 4: Organization}
     */
    protected function fixture(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_xxx'],
        ]);
        $server = PloiServer::create([
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
        $sites = [
            PloiSite::create([
                'ploi_server_id' => $server->id,
                'source_id' => 100,
                'domain' => 'app.example.com',
                'site_type' => 'laravel',
                'php_version' => '8.3',
                'repository_url' => 'git@github.com:acme/app.git',
                'repository_branch' => 'main',
                'web_directory' => '/public',
                'status' => 'installed',
                'removed_from_source' => false,
                'source_snapshot' => ['kind' => 'laravel'],
            ]),
            PloiSite::create([
                'ploi_server_id' => $server->id,
                'source_id' => 101,
                'domain' => 'api.example.com',
                'site_type' => 'php',
                'php_version' => '8.3',
                'repository_url' => 'git@github.com:acme/api.git',
                'repository_branch' => 'main',
                'web_directory' => '/public',
                'status' => 'installed',
                'removed_from_source' => false,
                'source_snapshot' => ['kind' => 'php'],
            ]),
            PloiSite::create([
                'ploi_server_id' => $server->id,
                'source_id' => 102,
                'domain' => 'wp.example.com',
                'site_type' => 'wordpress',
                'php_version' => '8.3',
                'repository_url' => null,
                'repository_branch' => null,
                'web_directory' => null,
                'status' => 'installed',
                'removed_from_source' => false,
                'source_snapshot' => ['kind' => 'wordpress'],
            ]),
        ];

        return [$credential, $server, $sites, $user, $org];
    }

    protected function targetServerId(Organization $org, User $user): string
    {
        return Server::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ])->id;
    }

    public function test_plan_creates_parent_children_and_steps_for_eligible_sites(): void
    {
        [$credential, $server, $sites, $user, $org] = $this->fixture();
        $targetId = $this->targetServerId($org, $user);

        $parent = (new MigrationPlanner())->plan(
            source: $server,
            selectedSiteIds: [$sites[0]->id, $sites[1]->id],
            targetServerId: $targetId,
            credential: $credential,
            userId: $user->id,
        );

        $this->assertInstanceOf(ImportServerMigration::class, $parent);
        $this->assertSame('ploi', $parent->source);
        $this->assertSame(42, $parent->source_server_id);
        $this->assertSame($targetId, $parent->target_server_id);
        $this->assertSame(ImportServerMigration::STATUS_PENDING, $parent->status);

        $children = ImportSiteMigration::query()->where('import_server_migration_id', $parent->id)->get();
        $this->assertCount(2, $children);
        $this->assertEqualsCanonicalizing(
            ['app.example.com', 'api.example.com'],
            $children->pluck('domain')->all()
        );

        $stepsPerSite = count(MigrationPlanner::STAGING_STEPS) + count(MigrationPlanner::CUTOVER_STEPS);
        $serverSteps = 4; // push_ssh_key, eligibility_scan, collect_manual_review, revoke_ssh_key
        $expectedTotal = $serverSteps + (2 * $stepsPerSite);
        $this->assertSame($expectedTotal, ImportMigrationStep::query()->where('import_server_migration_id', $parent->id)->count());
    }

    public function test_plan_emits_server_steps_first_and_revoke_last(): void
    {
        [$credential, $server, $sites, $user, $org] = $this->fixture();
        $targetId = $this->targetServerId($org, $user);

        $parent = (new MigrationPlanner())->plan(
            source: $server,
            selectedSiteIds: [$sites[0]->id],
            targetServerId: $targetId,
            credential: $credential,
            userId: $user->id,
        );

        $orderedKeys = ImportMigrationStep::query()
            ->where('import_server_migration_id', $parent->id)
            ->orderBy('sequence')
            ->pluck('step_key')
            ->all();

        $this->assertSame(ImportMigrationStep::KEY_PUSH_SSH_KEY, $orderedKeys[0]);
        $this->assertSame(ImportMigrationStep::KEY_ELIGIBILITY_SCAN, $orderedKeys[1]);
        $this->assertSame(ImportMigrationStep::KEY_REVOKE_SSH_KEY, end($orderedKeys));
    }

    public function test_plan_rejects_ineligible_sites(): void
    {
        [$credential, $server, $sites, $user, $org] = $this->fixture();
        $targetId = $this->targetServerId($org, $user);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/wordpress/i');

        (new MigrationPlanner())->plan(
            source: $server,
            selectedSiteIds: [$sites[2]->id], // wordpress
            targetServerId: $targetId,
            credential: $credential,
            userId: $user->id,
        );
    }

    public function test_plan_rejects_empty_selection(): void
    {
        [$credential, $server, , $user, $org] = $this->fixture();
        $targetId = $this->targetServerId($org, $user);

        $this->expectException(\RuntimeException::class);
        (new MigrationPlanner())->plan(
            source: $server,
            selectedSiteIds: [],
            targetServerId: $targetId,
            credential: $credential,
            userId: $user->id,
        );
    }
}
