<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DigitalOceanServiceFunctionsTest;

use App\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;

test('create functions namespace recombines uuid and key into an access key', function () {
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
    expect($result['access_key'])->toBe('the-uuid:the-secret');
    expect($result['namespace'])->toBe('fn-abc-123');
    expect($result['api_host'])->toBe('https://faas-nyc1.example');
    expect($result['region'])->toBe('nyc1');
});
