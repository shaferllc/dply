<?php

declare(strict_types=1);

namespace Tests\Feature\ServerOverviewEnginesPanelTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('overview shows engines panel with default marker', function () {
    $this->markTestSkipped('Database engines panel was moved off /overview; /databases page should host it. See dashboard refactor disposition Q4.');
});
test('overview renders engines empty state with install hint', function () {
    $this->markTestSkipped('Database engines panel was moved off /overview; /databases page should host it. See dashboard refactor disposition Q4.');
});
/**
 * @return array{0: User, 1: Server}
 */
function makeUserAndServer(): array
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
