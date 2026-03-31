<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class InternalWordpressSpikeTest extends TestCase
{
    public function test_internal_spike_returns_json_when_enabled(): void
    {
        Config::set('wordpress.internal_spike_enabled', true);

        $response = $this->get('/internal/spike');

        $response->assertOk();
        $response->assertJsonPath('app', 'dply-wordpress');
        $response->assertJsonPath('deploy.provider', 'wordpress');
        $response->assertJsonPath('deploy.status', 'deployed');
        $response->assertJsonPath('deploy.runtime', 'hosted');
        $response->assertJsonPath('deploy.trigger', 'internal_spike');
        $expectedSha = hash('sha256', 'spike|main|8.3|spike-app');
        $response->assertJsonPath('engine.sha', $expectedSha);
    }

    public function test_internal_spike_not_found_when_disabled(): void
    {
        Config::set('wordpress.internal_spike_enabled', false);

        $this->get('/internal/spike')->assertNotFound();
    }
}
