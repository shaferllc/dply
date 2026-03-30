<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Decorators\AuthenticatedDecorator;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

test('authenticated action allows authenticated users', function () {
    $user = User::factory()->create();
    Auth::login($user);

    $action = app(TestAuthenticatedAction::class);
    $result = $action->handle();

    expect($result)->toBe('authenticated');
});

test('authenticated action rejects unauthenticated users', function () {
    Auth::logout();

    $action = new TestAuthenticatedAction;
    $decorator = new AuthenticatedDecorator($action);

    expect(fn () => $decorator->handle())
        ->toThrow(HttpException::class);
});

test('authenticated action uses custom guard', function () {
    $user = User::factory()->create();
    Auth::guard('api')->login($user);

    $action = app(TestAuthenticatedWithCustomGuardAction::class);
    $result = $action->handle();

    expect($result)->toBe('authenticated');
});

test('authenticated action returns 401 for JSON requests', function () {
    Auth::logout();

    $this->withHeaders(['Accept' => 'application/json'])->get('/');
    $action = new TestAuthenticatedAction;
    $decorator = new AuthenticatedDecorator($action);

    expect(fn () => $decorator->handle())
        ->toThrow(HttpException::class);
});
