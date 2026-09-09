<?php

namespace Tests\Feature\ToolsPlaceholderMatchesTypeTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ownerUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function toolsHtmlFor(string $role): string
{
    $user = ownerUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'meta' => ['server_role' => $role],
    ]);

    // The document GET renders the lazy placeholder, not the hydrated page —
    // which is exactly the paint being asserted on here.
    return test()->actingAs($user)->get(route('servers.tools', $server))->getContent();
}

test('the skeleton on a database host does not promise a tool catalog', function () {
    $html = toolsHtmlFor('database');

    // A skeleton that predicts the wrong shape makes the page visibly rearrange
    // when the real render lands.
    expect($html)->toContain('This server type has no installable CLIs')
        ->and($html)->not->toContain('Installed CLIs and version managers');
});

test('the skeleton on an app host still promises one', function () {
    $html = toolsHtmlFor('application');

    expect($html)->toContain('Installed CLIs and version managers')
        ->and($html)->not->toContain('This server type has no installable CLIs');
});

test('the runtimes tab stub is absent from the database skeleton', function () {
    $appHtml = toolsHtmlFor('application');
    $dbHtml = toolsHtmlFor('database');

    // Counting occurrences: "Runtimes" appears in the app skeleton's tab strip
    // and must not appear in the database one, which renders no tab strip.
    expect(substr_count($appHtml, 'Runtimes'))->toBeGreaterThan(0)
        ->and(substr_count($dbHtml, 'Runtimes'))->toBe(0);
});
