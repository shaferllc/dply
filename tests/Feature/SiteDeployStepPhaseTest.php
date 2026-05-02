<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDeployStepPhaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_phase_for_artisan_migrate_is_release(): void
    {
        $this->assertSame(
            SiteDeployStep::PHASE_RELEASE,
            SiteDeployStep::defaultPhaseFor(SiteDeployStep::TYPE_ARTISAN_MIGRATE),
        );
    }

    public function test_default_phase_for_artisan_optimize_is_release(): void
    {
        $this->assertSame(
            SiteDeployStep::PHASE_RELEASE,
            SiteDeployStep::defaultPhaseFor(SiteDeployStep::TYPE_ARTISAN_OPTIMIZE),
        );
    }

    public function test_default_phase_for_dependency_installs_is_build(): void
    {
        foreach ([
            SiteDeployStep::TYPE_COMPOSER_INSTALL,
            SiteDeployStep::TYPE_NPM_CI,
            SiteDeployStep::TYPE_NPM_INSTALL,
            SiteDeployStep::TYPE_NPM_RUN,
        ] as $type) {
            $this->assertSame(
                SiteDeployStep::PHASE_BUILD,
                SiteDeployStep::defaultPhaseFor($type),
            );
        }
    }

    public function test_default_phase_for_artisan_caches_is_build(): void
    {
        foreach ([
            SiteDeployStep::TYPE_ARTISAN_CONFIG_CACHE,
            SiteDeployStep::TYPE_ARTISAN_ROUTE_CACHE,
            SiteDeployStep::TYPE_ARTISAN_VIEW_CACHE,
        ] as $type) {
            $this->assertSame(
                SiteDeployStep::PHASE_BUILD,
                SiteDeployStep::defaultPhaseFor($type),
            );
        }
    }

    public function test_default_phase_for_one_shot_scaffolding_is_build(): void
    {
        $this->assertSame(
            SiteDeployStep::PHASE_BUILD,
            SiteDeployStep::defaultPhaseFor(SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL),
        );
        $this->assertSame(
            SiteDeployStep::PHASE_BUILD,
            SiteDeployStep::defaultPhaseFor(SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL),
        );
    }

    public function test_default_phase_for_custom_step_is_build(): void
    {
        $this->assertSame(
            SiteDeployStep::PHASE_BUILD,
            SiteDeployStep::defaultPhaseFor(SiteDeployStep::TYPE_CUSTOM),
        );
    }

    public function test_default_phase_for_unknown_type_falls_back_to_build(): void
    {
        $this->assertSame(
            SiteDeployStep::PHASE_BUILD,
            SiteDeployStep::defaultPhaseFor('something_new'),
        );
    }

    public function test_user_phases_excludes_swap_and_restart(): void
    {
        $userPhases = SiteDeployStep::userPhases();

        $this->assertContains(SiteDeployStep::PHASE_BUILD, $userPhases);
        $this->assertContains(SiteDeployStep::PHASE_RELEASE, $userPhases);
        $this->assertNotContains(SiteDeployStep::PHASE_SWAP, $userPhases);
        $this->assertNotContains(SiteDeployStep::PHASE_RESTART, $userPhases);
    }

    public function test_all_phases_in_canonical_pipeline_order(): void
    {
        $this->assertSame(
            ['build', 'swap', 'release', 'restart'],
            SiteDeployStep::allPhases(),
        );
    }

    public function test_phase_scope_filters_to_a_single_phase(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);

        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 1,
            'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 600,
        ]);
        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 2,
            'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
            'phase' => SiteDeployStep::PHASE_RELEASE,
            'timeout_seconds' => 600,
        ]);

        $build = SiteDeployStep::query()
            ->where('site_id', $site->id)
            ->phase(SiteDeployStep::PHASE_BUILD)
            ->get();
        $release = SiteDeployStep::query()
            ->where('site_id', $site->id)
            ->phase(SiteDeployStep::PHASE_RELEASE)
            ->get();

        $this->assertCount(1, $build);
        $this->assertCount(1, $release);
        $this->assertSame(SiteDeployStep::TYPE_COMPOSER_INSTALL, $build->first()->step_type);
        $this->assertSame(SiteDeployStep::TYPE_ARTISAN_MIGRATE, $release->first()->step_type);
    }

    public function test_phase_column_defaults_to_build_when_not_set(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);

        // Create without setting phase — DB default should kick in.
        $step = SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 1,
            'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
            'timeout_seconds' => 600,
        ]);

        $this->assertSame(SiteDeployStep::PHASE_BUILD, $step->refresh()->phase);
    }
}
