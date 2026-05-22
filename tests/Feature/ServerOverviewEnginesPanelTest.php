<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerOverviewEnginesPanelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The "Database engines" panel was moved off Overview as part of
     * the dashboard refactor — the dedicated /servers/{id}/databases
     * page is the proper home for engine inventory + the install
     * hint. The panel hasn't been migrated to /databases yet, so
     * these tests are marked skipped: they document the assertions
     * that should hold once /databases absorbs the panel.
     */
    public function test_overview_shows_engines_panel_with_default_marker(): void
    {
        $this->markTestSkipped('Database engines panel was moved off /overview; /databases page should host it. See dashboard refactor disposition Q4.');
    }

    public function test_overview_renders_engines_empty_state_with_install_hint(): void
    {
        $this->markTestSkipped('Database engines panel was moved off /overview; /databases page should host it. See dashboard refactor disposition Q4.');
    }

    /**
     * @return array{0: User, 1: Server}
     */
    private function makeUserAndServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'meta' => ['webserver' => 'nginx'],
        ]);

        return [$user, $server];
    }
}
