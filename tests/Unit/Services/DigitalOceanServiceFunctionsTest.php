<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitalOceanServiceFunctionsTest extends TestCase
{
    public function test_create_functions_namespace_recombines_uuid_and_key_into_an_access_key(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/functions/namespaces' => Http::response([
                'namespace' => [
                    'api_host' => 'https://faas-nyc1.example',
                    'namespace' => 'fn-abc-123',
                    'uuid' => 'the-uuid',
                    'key' => 'the-secret',
                    'region' => 'nyc1',
                ],
            ], 200),
        ]);

        $result = (new DigitalOceanService('tok'))->createFunctionsNamespace('nyc1', 'dply-demo');

        // OpenWhisk auth is `uuid:key` — the deployer splits on the colon.
        $this->assertSame('the-uuid:the-secret', $result['access_key']);
        $this->assertSame('fn-abc-123', $result['namespace']);
        $this->assertSame('https://faas-nyc1.example', $result['api_host']);
        $this->assertSame('nyc1', $result['region']);
    }
}
