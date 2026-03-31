<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_returns_ok_when_database_available(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('app', 'dply-wordpress')
            ->assertJsonPath('ok', true)
            ->assertJsonPath('checks.database', true);
    }
}
