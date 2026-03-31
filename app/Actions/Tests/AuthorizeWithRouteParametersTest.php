<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Route::get('/authorize-bindings/{someRouteParameter}', AuthorizeWithRouteParametersTestAction::class);
});

it('resolves route parameters as authorization arguments', function () {
    $this->withoutMiddleware()
        ->get('/authorize-bindings/authorized')
        ->assertOk()
        ->assertExactJson(['authorized']);

    $this->withoutMiddleware()
        ->get('/authorize-bindings/unauthorized')
        ->assertForbidden();
});
