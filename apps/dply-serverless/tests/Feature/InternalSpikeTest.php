<?php

namespace Tests\Feature;

use Dply\Core\Security\WebhookSignature;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class InternalSpikeTest extends TestCase
{
    public function test_internal_spike_json_uses_local_stub_by_default(): void
    {
        Config::set('serverless.provisioner', 'local');

        $response = $this->get('/internal/spike');

        $response->assertOk();
        $response->assertJsonPath('app', 'dply-serverless');
        $response->assertJsonPath('dply_core.webhook_signature_class', WebhookSignature::class);
        $response->assertJsonPath('provision.provider', 'local');
        $response->assertJsonPath('provision.function_arn', 'arn:dply:local:function:spike-fn');
        $response->assertJsonPath('engine.sha', 'local-revision-1');
    }

    public function test_internal_spike_json_uses_aws_stub_when_configured(): void
    {
        Config::set('serverless.provisioner', 'aws');

        $response = $this->get('/internal/spike');

        $response->assertOk();
        $response->assertJsonPath('provision.provider', 'aws');
        $response->assertJsonPath('provision.function_arn', 'arn:aws:lambda:us-east-1:000000000000:function:spike-fn');
        $response->assertJsonPath('engine.sha', 'aws-stub-revision-1');
    }

    public function test_internal_spike_json_uses_digitalocean_stub_when_configured(): void
    {
        Config::set('serverless.provisioner', 'digitalocean');

        $response = $this->get('/internal/spike');

        $response->assertOk();
        $response->assertJsonPath('provision.provider', 'digitalocean');
        $response->assertJsonPath('provision.function_arn', 'do:function:stub:spike-fn');
        $response->assertJsonPath('engine.sha', 'digitalocean-stub-revision-1');
    }
}
