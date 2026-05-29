<?php

namespace Tests\Feature\Marketing\PricingPageTest;

use Laravel\Pennant\Feature;
use Tests\Concerns\WithFeatures;

uses(WithFeatures::class);

beforeEach(function () {
    Feature::define('global.billing_enabled', fn () => true);
    Feature::flushCache();
});

test('pricing page renders the flat plan cards', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('Simple plans, priced by server count.')
        ->assertSee('Free')
        ->assertSee('Starter')
        ->assertSee('Pro')
        ->assertSee('Business')
        ->assertSee('Start free')
        ->assertSee('Estimate your bill');
});

test('pricing page lists every configured plan price', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    // Free $0, Starter $9, Pro $19, Business $39.
    $response->assertSee('$0')
        ->assertSee('$9')
        ->assertSee('$19')
        ->assertSee('$39');
});

test('pricing page shows server ceilings for each plan', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertSee('1 server')
        ->assertSee('Up to 3 servers')
        ->assertSee('Up to 10 servers')
        ->assertSee('Unlimited servers');
});

test('pricing page advertises managed products a la carte', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertSee('Managed products, à la carte', false)
        ->assertSee('dply Edge')
        ->assertSee('dply Cloud')
        ->assertSee('Serverless')
        ->assertSee('Managed products require a paid plan (Starter or higher).');
});

test('pricing page promotes the free first server', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('Your first server is free, forever.');
});

test('pricing page has faq', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertSee('Frequently asked')
        ->assertSee('Why per-server count', false);
});
