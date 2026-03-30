<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(TestCase::class);

test('permission action allows user with required permission', function () {
    $user = TestUserWithPermissions::factory()->create();
    $user->permissions = ['users.view'];
    Auth::login($user);

    $action = TestPermissionAction::make();
    $result = $action->handle();

    expect($result)->toBe('authorized');
});

test('permission action rejects user without required permission', function () {
    $user = TestUserWithPermissions::factory()->create();
    $user->permissions = ['users.create'];
    Auth::login($user);

    $action = TestPermissionAction::make();

    expect(fn () => $action->handle())
        ->toThrow(HttpResponseException::class);
});

test('permission action allows user with any required permission (OR)', function () {
    $user = TestUserWithPermissions::factory()->create();
    $user->permissions = ['users.edit'];
    Auth::login($user);

    $action = TestPermissionMultipleAction::make();
    $result = $action->handle();

    expect($result)->toBe('authorized');
});

test('permission action rejects unauthenticated users', function () {
    Auth::logout();

    $action = TestPermissionAction::make();

    expect(fn () => $action->handle())
        ->toThrow(HttpResponseException::class);
});
