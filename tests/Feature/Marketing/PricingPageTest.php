<?php

namespace Tests\Feature\Marketing;

use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class PricingPageTest extends TestCase
{
    use WithFeatures;

    protected array $features = ['global.billing_enabled'];

    public function test_pricing_page_renders_new_standard_and_enterprise_cards(): void
    {
        $response = $this->withoutMiddleware()->get(route('pricing'));

        $response->assertOk()
            ->assertSee('One plan. Pay for what you run.')
            ->assertSee('Standard')
            ->assertSee('Enterprise')
            ->assertSee('Start 14-day free trial')
            ->assertSee('Talk to sales')
            ->assertSee('Estimate your bill');
    }

    public function test_pricing_page_lists_all_five_tiers(): void
    {
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
    }

    public function test_pricing_page_shows_example_fleet_amounts(): void
    {
        $response = $this->withoutMiddleware()->get(route('pricing'));

        $response->assertSee('Indie dev')
            ->assertSee('Small team')
            ->assertSee('Growing fleet');
    }

    public function test_pricing_page_has_faq(): void
    {
        $response = $this->withoutMiddleware()->get(route('pricing'));

        $response->assertSee('Frequently asked')
            ->assertSee('Why per-server');
    }
}
