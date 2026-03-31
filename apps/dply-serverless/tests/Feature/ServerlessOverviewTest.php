<?php

namespace Tests\Feature;

use App\Features\ServerlessFeature;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class ServerlessOverviewTest extends TestCase
{
    public function test_serverless_overview_renders_when_feature_enabled(): void
    {
        $this->get('/serverless')
            ->assertOk()
            ->assertSee(config('app.name'));
    }

    public function test_serverless_overview_returns_not_found_when_feature_disabled(): void
    {
        Feature::for(null)->deactivate(ServerlessFeature::PUBLIC_DASHBOARD);

        $this->get('/serverless')->assertNotFound();
    }
}
