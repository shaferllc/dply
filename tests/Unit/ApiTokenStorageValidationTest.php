<?php


namespace Tests\Unit\ApiTokenStorageValidationTest;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use InvalidArgumentException;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('create token rejects unknown ability', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('made.up.ability');

    ApiToken::createToken($user, $org, 'bad', null, ['servers.read', 'made.up.ability']);
});

test('create token accepts wildcard prefix when matches catalog', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    ['token' => $token] = ApiToken::createToken($user, $org, 'wild', null, ['servers.*']);

    expect($token->abilities)->toBe(['servers.*']);
});