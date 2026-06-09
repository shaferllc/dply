<?php

namespace Tests\Unit\Services\ServerSystemUserDeletionPolicyTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerSystemUserDeletionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('blocks root dply and deploy users', function () {
    $policy = new ServerSystemUserDeletionPolicy;
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create(['ssh_user' => 'mydeploy']);

    config(['server_provision.deploy_ssh_user' => 'dply']);

    expect($policy->deletionBlockedReason($server, 'root'))->not->toBeNull();
    expect($policy->deletionBlockedReason($server, 'dply'))->not->toBeNull();
    expect($policy->deletionBlockedReason($server, 'mydeploy'))->not->toBeNull();
    expect($policy->deletionBlockedReason($server, 'DPLY'))->not->toBeNull();
});

test('blocks when site still uses user', function () {
    $policy = new ServerSystemUserDeletionPolicy;
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create(['ssh_user' => 'dply-main']);
    $user = User::factory()->create();

    Site::factory()->for($server)->for($org)->for($user)->create([
        'php_fpm_user' => 'appu1',
    ]);

    expect($policy->deletionBlockedReason($server, 'appu1'))->not->toBeNull();
});

test('allows when unused and not protected', function () {
    $policy = new ServerSystemUserDeletionPolicy;
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create(['ssh_user' => 'dply-main']);
    $user = User::factory()->create();

    Site::factory()->for($server)->for($org)->for($user)->create([
        'php_fpm_user' => 'other',
    ]);

    expect($policy->deletionBlockedReason($server, 'orphan'))->toBeNull();
});

test('is protected flags root dply and deploy', function () {
    $policy = new ServerSystemUserDeletionPolicy;
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create(['ssh_user' => 'mydeploy']);

    config(['server_provision.deploy_ssh_user' => 'dply']);

    expect($policy->isProtected($server, 'root'))->toBeTrue();
    expect($policy->isProtected($server, 'dply'))->toBeTrue();
    expect($policy->isProtected($server, 'DPLY'))->toBeTrue();
    expect($policy->isProtected($server, 'mydeploy'))->toBeTrue();
    expect($policy->isProtected($server, 'appuser'))->toBeFalse();
    expect($policy->isProtected($server, ''))->toBeFalse();
});

test('site counts by effective user', function () {
    $policy = new ServerSystemUserDeletionPolicy;
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create(['ssh_user' => 'deploy']);
    $user = User::factory()->create();

    Site::factory()->for($server)->for($org)->for($user)->create(['php_fpm_user' => null]);
    Site::factory()->for($server)->for($org)->for($user)->create(['php_fpm_user' => 'appx']);

    $counts = $policy->siteCountsByUsername($server);

    expect($counts['deploy'] ?? 0)->toBe(1);
    expect($counts['appx'] ?? 0)->toBe(1);
});
