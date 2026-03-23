<?php

namespace Tests\Unit;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenDeployerTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployer_cannot_use_commands_run_even_with_star_token(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'deployer']);

        $token = new ApiToken([
            'abilities' => ['*'],
        ]);
        $token->setRelation('user', $user);
        $token->setRelation('organization', $org);

        $this->assertFalse($token->allows('commands.run'));
        $this->assertTrue($token->allows('sites.deploy'));
        $this->assertTrue($token->allows('sites.read'));
    }
}
