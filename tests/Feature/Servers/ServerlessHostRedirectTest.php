<?php

namespace Tests\Feature\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerlessHostRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_serverless_host_overview_redirects_to_the_function_workspace(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);
        $function = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
        ]);

        // The serverless namespace host is an implementation detail — the
        // server overview must bounce to the function workspace.
        $this->actingAs($user)
            ->get(route('servers.overview', $server))
            ->assertRedirect(route('sites.show', ['server' => $server, 'site' => $function]));
    }
}
