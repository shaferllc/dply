<?php


namespace Tests\Unit\ApiTokenDeployerTest;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('deployer cannot use commands run even with star token', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);

    $token = new ApiToken([
        'abilities' => ['*'],
    ]);
    $token->setRelation('user', $user);
    $token->setRelation('organization', $org);

    expect($token->allows('commands.run'))->toBeFalse();
    expect($token->allows('sites.deploy'))->toBeTrue();
    expect($token->allows('sites.read'))->toBeTrue();
});