<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsAuthorized;
use App\Actions\Decorators\AuthorizedDecorator;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

test('authorized action allows when gate allows', function () {
    $user = User::factory()->create();
    Auth::login($user);

    Gate::define('view-reports', fn () => true);

    $action = app(TestAuthorizedAction::class);
    $result = $action->handle();

    expect($result)->toBe('authorized');
});

test('authorized action rejects when gate denies', function () {
    $user = User::factory()->create();
    Auth::login($user);

    Gate::define('view-reports', fn () => false);

    $action = new TestAuthorizedAction;
    $decorator = new AuthorizedDecorator($action);

    expect(fn () => $decorator->handle())
        ->toThrow(HttpException::class);
});

test('authorized action passes correct arguments to gate', function () {
    $user = User::factory()->create();
    Auth::login($user);

    Gate::define('view-user', fn (User $targetUser) => auth()->user()?->id === $targetUser->id);

    $action = app(TestAuthorizedWithArgumentsAction::class);
    $result = $action->handle($user);

    expect($result)->toBe('authorized');
});

test('authorized action uses default ability from class name', function () {
    $user = User::factory()->create();
    Auth::login($user);

    Gate::define('testauthorizedaction', fn () => true);

    $action = new class extends Actions
    {
        use AsAuthorized;

        public function handle(): string
        {
            return 'authorized';
        }
    };

    $result = $action->handle();
    expect($result)->toBe('authorized');
});
