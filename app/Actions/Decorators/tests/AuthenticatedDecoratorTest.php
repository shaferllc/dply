<?php

declare(strict_types=1);

use App\Actions\Decorators\AuthenticatedDecorator;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

describe('AuthenticatedDecorator', function () {
    it('allows authenticated users to execute action', function () {
        $user = User::factory()->create();
        Auth::login($user);

        $action = new class
        {
            public function handle(): string
            {
                return 'success';
            }
        };
        $decorator = new AuthenticatedDecorator($action);

        $result = $decorator->handle();

        expect($result)->toBe('success');
    });

    it('rejects unauthenticated users with JSON request', function () {
        Auth::logout();

        $action = new class
        {
            public function handle(): string
            {
                return 'success';
            }
        };
        $decorator = new AuthenticatedDecorator($action);

        // The decorator reads the *bound* request, not the pending test-request
        // headers withHeaders() sets. Without a JSON-expecting request it takes
        // the redirect()->send(); exit; branch, which kills the PHPUnit process
        // outright (silent exit 2, no summary, rest of the suite never runs).
        $this->app->instance('request', Request::create('/', 'GET', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]));

        expect(fn () => $decorator->handle())
            ->toThrow(HttpException::class);
    });

    it('calls handleUnauthenticated when action defines it', function () {
        Auth::logout();

        $action = new class
        {
            public bool $handleUnauthenticatedCalled = false;

            public function handle(): string
            {
                return 'success';
            }

            public function handleUnauthenticated(): void
            {
                $this->handleUnauthenticatedCalled = true;
                abort(403, 'Custom unauthenticated');
            }
        };
        $decorator = new AuthenticatedDecorator($action);

        expect(fn () => $decorator->handle())
            ->toThrow(HttpException::class);

        expect($action->handleUnauthenticatedCalled)->toBeTrue();
    });
});
