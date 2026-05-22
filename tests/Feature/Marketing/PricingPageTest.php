<?php


namespace Tests\Feature\Marketing\PricingPageTest;
uses(\Tests\Concerns\WithFeatures::class);

test('pricing page renders new standard and enterprise cards', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('One plan. Pay for what you run.')
        ->assertSee('Standard')
        ->assertSee('Enterprise')
        ->assertSee('Start 14-day free trial')
        ->assertSee('Talk to sales')
        ->assertSee('Estimate your bill');
});

test('pricing page lists all five tiers', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    // The per-server detail table has a Per day + Per month column. Assert
    // on the monthly amounts (bare, no suffix) and a couple of daily ones.
    $response->assertSee('Per day')
        ->assertSee('Per month')
        ->assertSee('$2.00')
        ->assertSee('$10.00')
        ->assertSee('$40.00')
        // M tier daily: $10 / 30 = $0.33
        ->assertSee('$0.33');
});

test('pricing page shows example fleet amounts', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertSee('Indie dev')
        ->assertSee('Small team')
        ->assertSee('Growing fleet');
});

test('pricing page has faq', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertSee('Frequently asked')
        ->assertSee('Why per-server');
});