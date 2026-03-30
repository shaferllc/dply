<?php

declare(strict_types=1);

use App\Actions\Decorators\AuthenticatedDecorator;
use App\Models\User;
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

        $this->withHeaders(['Accept' => 'application/json']);

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
