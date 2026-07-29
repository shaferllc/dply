<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DigitalOceanServiceInspectDropletPresenceTest;

use App\Modules\Cloud\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;

test('inspect reports gone on 404', function () {
    Http::fake([
        'api.digitalocean.com/v2/droplets/555' => Http::response(['message' => 'Not found'], 404),
    ]);

    $svc = new DigitalOceanService('do-token-test');
    $result = $svc->inspectDropletPresence(555);

    expect($result['state'])->toBe('gone');
});
test('inspect reports present on 200', function () {
    Http::fake([
        'api.digitalocean.com/v2/droplets/777' => Http::response(['droplet' => ['id' => 777]], 200),
    ]);

    $svc = new DigitalOceanService('do-token-test');
    $result = $svc->inspectDropletPresence(777);

    expect($result['state'])->toBe('present');
});
test('inspect reports unknown on other errors', function () {
    Http::fake([
        'api.digitalocean.com/v2/droplets/888' => Http::response(['message' => 'Server error'], 500),
    ]);

    $svc = new DigitalOceanService('do-token-test');
    $result = $svc->inspectDropletPresence(888);

    expect($result['state'])->toBe('unknown');
    expect($result)->toHaveKey('detail');
});
