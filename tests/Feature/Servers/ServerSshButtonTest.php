<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\ServerSshButtonTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ssh offers every key the agent holds; past the server's MaxAuthTries it
// disconnects with "Too many authentication failures". The button now lets a
// person pin one key, offered alone with IdentitiesOnly.
test('the server SSH button can offer one chosen key instead of the whole agent', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.7',
        'ssh_user' => 'root',
        // The SSH row lives in the sidebar, which shows once setup is done.
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    $this->actingAs($user)
        ->blade('<x-server-workspace-shell :server="$server">Body</x-server-workspace-shell>', ['server' => $server])
        // The deploy user, not the root the record was created with.
        ->assertSee('dply@203.0.113.7')
        ->assertDontSee('root@203.0.113.7')
        ->assertSee('Set your SSH key')
        ->assertSee('ssh -o IdentitiesOnly=yes -i', false)
        ->assertSee('dply.sshKeyPath', false)
        // An ssh:// link cannot carry the key, so it is only used without one.
        ->assertSee('if (uri && ! this.sshKeyPath)', false);
});
