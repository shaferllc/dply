<?php

declare(strict_types=1);

namespace Tests\Feature\Api\AccountApiTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: User, 2: string, 3: ApiToken}
 */
function accountContext(array $abilities, string $tokenName = 'dply CLI'): array
{
    $org = Organization::factory()->create(['name' => 'Acme Ops']);
    $user = User::factory()->create(['name' => 'Taylor', 'email' => 'taylor@example.com']);
    $org->users()->attach($user->id, ['role' => 'owner']);

    ['token' => $token, 'plaintext' => $plain] = ApiToken::createToken(
        $user,
        $org,
        $tokenName,
        null,
        $abilities,
    );

    return [$org, $user, $plain, $token];
}

test('account show returns user org and token metadata', function (): void {
    [, , $plain] = accountContext(['account.read', 'servers.read']);

    $this->getJson('/api/v1/account', [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonPath('data.user.email', 'taylor@example.com')
        ->assertJsonPath('data.organization.name', 'Acme Ops')
        ->assertJsonPath('data.organization.role', 'owner')
        ->assertJsonPath('data.token.is_cli', true)
        ->assertJsonPath('data.token.is_current', true);
});

test('account organizations lists memberships', function (): void {
    [, $user, $plain] = accountContext(['account.read']);

    $other = Organization::factory()->create(['name' => 'Side Org']);
    $other->users()->attach($user->id, ['role' => 'member']);

    $this->getJson('/api/v1/account/organizations', [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['name' => 'Acme Ops', 'is_current' => true])
        ->assertJsonFragment(['name' => 'Side Org', 'role' => 'member']);
});

test('account sessions lists cli tokens for org admins', function (): void {
    [$org, $user, $plain] = accountContext(['account.read']);

    ApiToken::createToken($user, $org, 'dply CLI', null, ['account.read']);
    ApiToken::createToken($user, $org, 'CI token', null, ['servers.read']);

    $response = $this->getJson('/api/v1/account/sessions', [
        'Authorization' => 'Bearer '.$plain,
    ]);

    $response->assertOk();
    expect(collect($response->json('data'))->every(fn (array $row): bool => ($row['is_cli'] ?? false) === true))->toBeTrue();
    expect(collect($response->json('data')))->toHaveCount(2);
});

test('account sessions destroy revokes cli session', function (): void {
    [$org, $user, $plain, $current] = accountContext(['account.read', 'account.write']);

    ['token' => $other] = ApiToken::createToken($user, $org, 'dply CLI', null, ['account.read']);

    $this->deleteJson('/api/v1/account/sessions/'.$other->id, [], [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonPath('revoked_current', false);

    expect(ApiToken::query()->find($other->id))->toBeNull();
    expect(ApiToken::query()->find($current->id))->not->toBeNull();
});

test('account sessions destroy clears current token when revoking self', function (): void {
    [, , $plain, $current] = accountContext(['account.read', 'account.write']);

    $this->deleteJson('/api/v1/account/sessions/'.$current->id, [], [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonPath('revoked_current', true);

    expect(ApiToken::query()->find($current->id))->toBeNull();
});

test('account endpoints require account abilities', function (): void {
    [, , $plain] = accountContext(['servers.read']);

    $this->getJson('/api/v1/account', [
        'Authorization' => 'Bearer '.$plain,
    ])->assertForbidden();
});
