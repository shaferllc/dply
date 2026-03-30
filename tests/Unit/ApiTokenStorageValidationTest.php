<?php

namespace Tests\Unit;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ApiTokenStorageValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_token_rejects_unknown_ability(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('made.up.ability');

        ApiToken::createToken($user, $org, 'bad', null, ['servers.read', 'made.up.ability']);
    }

    public function test_create_token_accepts_wildcard_prefix_when_matches_catalog(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        ['token' => $token] = ApiToken::createToken($user, $org, 'wild', null, ['servers.*']);

        $this->assertSame(['servers.*'], $token->abilities);
    }
}
