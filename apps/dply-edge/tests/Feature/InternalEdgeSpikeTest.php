<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class InternalEdgeSpikeTest extends TestCase
{
    public function test_internal_spike_returns_json_when_enabled(): void
    {
        Config::set('edge.internal_spike_enabled', true);

        $response = $this->get('/internal/spike');

        $response->assertOk();
        $response->assertJsonPath('app', 'dply-edge');
        $response->assertJsonPath('deploy.provider', 'edge');
        $response->assertJsonPath('deploy.status', 'stub');
        $response->assertJsonPath('engine.sha', 'edge-stub-revision-1');
    }

    public function test_internal_spike_not_found_when_disabled(): void
    {
        Config::set('edge.internal_spike_enabled', false);

        $this->get('/internal/spike')->assertNotFound();
    }
}
