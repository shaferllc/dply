<?php

namespace Tests\Feature;

use App\Features\ServerlessFeature;
use Dply\Core\Security\WebhookSignature;
use Illuminate\Support\Facades\Config;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
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
        Config::set('serverless.aws.use_real_sdk', false);

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

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function roadmapSpikeProvider(): iterable
    {
        yield 'azure' => ['azure', 'azure:function:stub:spike-fn', 'azure-stub-revision-1'];
        yield 'gcp' => ['gcp', 'gcp:function:stub:spike-fn', 'gcp-stub-revision-1'];
        yield 'cloudflare' => ['cloudflare', 'cloudflare:worker:stub:spike-fn', 'cloudflare-stub-revision-1'];
        yield 'netlify' => ['netlify', 'netlify:function:stub:spike-fn', 'netlify-stub-revision-1'];
        yield 'vercel' => ['vercel', 'vercel:function:stub:spike-fn', 'vercel-stub-revision-1'];
    }

    #[DataProvider('roadmapSpikeProvider')]
    public function test_internal_spike_json_uses_roadmap_stub_when_configured(
        string $provisioner,
        string $expectedArn,
        string $expectedSha,
    ): void {
        Config::set('serverless.provisioner', $provisioner);

        $response = $this->get('/internal/spike');

        $response->assertOk();
        $response->assertJsonPath('provision.provider', $provisioner);
        $response->assertJsonPath('provision.function_arn', $expectedArn);
        $response->assertJsonPath('engine.sha', $expectedSha);
    }

    public function test_internal_spike_returns_not_found_when_feature_disabled(): void
    {
        Feature::for(null)->deactivate(ServerlessFeature::INTERNAL_SPIKE);

        $this->get('/internal/spike')->assertNotFound();
    }
}
