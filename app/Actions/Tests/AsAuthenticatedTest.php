<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Decorators\AuthenticatedDecorator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('authenticated action allows authenticated users', function () {
    $user = User::factory()->create();
    Auth::login($user);

    $action = app(TestAuthenticatedAction::class);
    $result = $action->handle();

    expect($result)->toBe('authenticated');
});

test('authenticated action redirects unauthenticated users to login', function () {
    Auth::logout();

    $action = new TestAuthenticatedAction;
    $decorator = new AuthenticatedDecorator($action);

    // A browser request redirects rather than 401s, and the redirect travels as
    // an HttpResponseException. This used to assert HttpException and never
    // reached the assertion at all: the decorator called exit and took the
    // process with it (ISS-018).
    expect(fn () => $decorator->handle())
        ->toThrow(HttpResponseException::class);
});

test('authenticated action uses custom guard', function () {
    // TestAuthenticatedWithCustomGuardAction declares the guard 'api', and dply
    // configures only 'web'. Defined here for the duration of the test rather
    // than in config/auth.php: the application genuinely has no 'api' guard,
    // and inventing one so a test can pass would be the tail wagging the dog.
    // Resolving an undefined guard throws where Pest cannot report it, which is
    // why this file used to exit 2 with no output at all and take the whole App
    // suite's reporting down with it (ISS-018).
    config()->set('auth.guards.api', ['driver' => 'session', 'provider' => 'users']);

    $user = User::factory()->create();
    Auth::guard('api')->login($user);

    $action = app(TestAuthenticatedWithCustomGuardAction::class);

    expect($action->handle())->toBe('authenticated');
});

test('authenticated action rejects a user authenticated only on the default guard', function () {
    // The point of the test above is that the DECLARED guard is the one checked.
    // Without this, it would pass just as happily if the decorator ignored
    // $authGuard and used the default, since the same user would be logged in
    // either way.
    config()->set('auth.guards.api', ['driver' => 'session', 'provider' => 'users']);

    $user = User::factory()->create();
    Auth::guard('web')->login($user);

    $decorator = new AuthenticatedDecorator(new TestAuthenticatedWithCustomGuardAction);

    expect(fn () => $decorator->handle())->toThrow(HttpResponseException::class);
});

test('authenticated action returns 401 for JSON requests', function () {
    Auth::logout();

    $this->withHeaders(['Accept' => 'application/json'])->get('/');
    $action = new TestAuthenticatedAction;
    $decorator = new AuthenticatedDecorator($action);

    expect(fn () => $decorator->handle())
        ->toThrow(HttpException::class);
});
