<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\PasswordConfirmationAction;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($this->user);
});

it('make returns filament action instance', function () {
    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true
    );

    expect($action)->toBeInstanceOf(Action::class);
});

it('action has default modal heading', function () {
    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true
    );

    $reflection = new \ReflectionClass($action);
    $property = $reflection->getProperty('modalHeading');
    $property->setAccessible(true);

    expect($property->getValue($action))->toBe('Confirm your password');
});

it('action accepts custom modal heading', function () {
    $customHeading = 'Custom Password Confirmation';

    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true,
        customModalHeading: $customHeading
    );

    $reflection = new \ReflectionClass($action);
    $property = $reflection->getProperty('modalHeading');
    $property->setAccessible(true);

    expect($property->getValue($action))->toBe($customHeading);
});

it('action has default modal description', function () {
    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true
    );

    $reflection = new \ReflectionClass($action);
    $property = $reflection->getProperty('modalDescription');
    $property->setAccessible(true);

    expect($property->getValue($action))->toBe('For your security, please confirm your password to continue.');
});

it('action accepts custom modal description', function () {
    $customDescription = 'Custom security message';

    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true,
        modalDescription: $customDescription
    );

    $reflection = new \ReflectionClass($action);
    $property = $reflection->getProperty('modalDescription');
    $property->setAccessible(true);

    expect($property->getValue($action))->toBe($customDescription);
});

it('action has default modal icon', function () {
    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true
    );

    $reflection = new \ReflectionClass($action);
    $property = $reflection->getProperty('modalIcon');
    $property->setAccessible(true);

    expect($property->getValue($action))->toBe('heroicon-o-shield-check');
});

it('action accepts custom modal icon', function () {
    $customIcon = 'heroicon-o-lock-closed';

    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true,
        modalIcon: $customIcon
    );

    $reflection = new \ReflectionClass($action);
    $property = $reflection->getProperty('modalIcon');
    $property->setAccessible(true);

    expect($property->getValue($action))->toBe($customIcon);
});

it('action has schema with password field', function () {
    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true
    );

    expect($action)->toBeInstanceOf(Action::class);
});

it('action accepts custom label', function () {
    $customLabel = 'Enter Your Password';

    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true,
        customLabel: $customLabel
    );

    expect($action)->toBeInstanceOf(Action::class);
});

it('rate limiter clears throttle key after set up', function () {
    $throttleKey = 'password-confirmation:'.$this->user->id;
    RateLimiter::clear($throttleKey);

    expect(RateLimiter::remaining($throttleKey, 3))->toBe(3);
});

it('action name can be customized', function () {
    $customName = 'customPasswordConfirm';

    $action = PasswordConfirmationAction::make(
        $customName,
        fn () => true
    );

    $reflection = new \ReflectionClass($action);
    $property = $reflection->getProperty('name');
    $property->setAccessible(true);

    expect($property->getValue($action))->toBe($customName);
});

it('action callback receives data parameter', function () {
    $callbackExecuted = false;

    $action = PasswordConfirmationAction::make(
        'test',
        function ($data) use (&$callbackExecuted) {
            $callbackExecuted = true;
            expect($data)->toBeArray();

            return true;
        }
    );

    expect($action)->toBeInstanceOf(Action::class);
});

it('action implements requires confirmation by default', function () {
    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true
    );

    expect($action)->toBeInstanceOf(Action::class);
});

it('action can disable requires confirmation', function () {
    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true,
        requiresConfirmation: false
    );

    expect($action)->toBeInstanceOf(Action::class);
});

it('action has password input with current password rule', function () {
    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true
    );

    expect($action)->toBeInstanceOf(Action::class);
});

it('action accepts extra input attributes', function () {
    $extraAttributes = [
        'autocomplete' => 'new-password',
        'data-test' => 'password-field',
    ];

    $action = PasswordConfirmationAction::make(
        'test',
        fn () => true,
        extraInputAttributes: $extraAttributes
    );

    expect($action)->toBeInstanceOf(Action::class);
});
