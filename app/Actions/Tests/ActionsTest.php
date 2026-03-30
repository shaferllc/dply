<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->action = new ConcreteAction;
});

it('get controller middleware returns expected middleware', function () {
    $middleware = $this->action->getControllerMiddleware();

    expect($middleware)->toBeArray()
        ->toContain('auth:sanctum')
        ->toContain('api')
        ->toContain('verified');
});

it('get validation failure throws http response exception', function () {
    $validator = Validator::make(
        ['email' => 'invalid-email'],
        ['email' => 'required|email']
    );

    $validator->fails();

    expect(fn () => $this->action->getValidationFailure($validator))
        ->toThrow(HttpResponseException::class);
});

it('get validation failure returns unprocessable entity status', function () {
    $validator = Validator::make(
        ['email' => 'invalid-email'],
        ['email' => 'required|email']
    );

    $validator->fails();

    try {
        $this->action->getValidationFailure($validator);
    } catch (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(422);
    }
});

it('get validation failure returns json errors', function () {
    $validator = Validator::make(
        ['email' => 'invalid-email'],
        ['email' => 'required|email']
    );

    $validator->fails();

    try {
        $this->action->getValidationFailure($validator);
    } catch (HttpResponseException $e) {
        $content = json_decode($e->getResponse()->getContent(), true);
        expect($content)->toHaveKey('errors')
            ->and($content['errors'])->toHaveKey('email');
    }
});

it('unauthorized response throws http response exception', function () {
    expect(fn () => $this->action->unauthorizedResponse())
        ->toThrow(HttpResponseException::class);
});

it('unauthorized response returns custom message', function () {
    $customMessage = 'Custom unauthorized message';

    try {
        $this->action->unauthorizedResponse($customMessage);
    } catch (HttpResponseException $e) {
        $content = json_decode($e->getResponse()->getContent(), true);
        expect($content['unauthorized'])->toBe($customMessage);
    }
});

it('unauthorized response returns default message when no message provided', function () {
    try {
        $this->action->unauthorizedResponse();
    } catch (HttpResponseException $e) {
        $content = json_decode($e->getResponse()->getContent(), true);
        expect($content['unauthorized'])->toBe('You are not authorized to perform this action.');
    }
});

it('unauthorized response returns unprocessable entity status', function () {
    try {
        $this->action->unauthorizedResponse();
    } catch (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(422);
    }
});

/**
 * Concrete implementation of Actions for testing purposes
 */
class ConcreteAction extends Actions
{
    public function getControllerMiddleware(): array
    {
        return ['auth:sanctum', 'api', 'verified'];
    }

    public function handle(): void
    {
        // Empty implementation for testing
    }
}
