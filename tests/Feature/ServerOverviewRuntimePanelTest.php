<?php

declare(strict_types=1);

namespace Tests\Feature\ServerOverviewRuntimePanelTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('overview shows polyglot runtime inventory panel', function () {
    $this->markTestSkipped('Installed runtimes panel was moved off /overview; /services page should host it. See dashboard refactor disposition Q4.');
});
test('overview renders empty state when no runtimes installed', function () {
    $this->markTestSkipped('Installed runtimes panel was moved off /overview; /services page should host it. See dashboard refactor disposition Q4.');
});
test('overview renders php only for legacy servers', function () {
    $this->markTestSkipped('Installed runtimes panel was moved off /overview; /services page should host it. See dashboard refactor disposition Q4.');
});
function seedUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
