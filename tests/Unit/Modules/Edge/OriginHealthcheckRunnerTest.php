<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Edge\OriginHealthcheckRunnerTest;

use App\Models\Site;
use App\Modules\Edge\Services\OriginHealthcheckRunner;
use Illuminate\Support\Facades\Http;

function edgeSiteWithOrigin(string $url, string $path = '/'): Site
{
    $site = new Site;
    $site->forceFill([
        'meta' => [
            'edge' => [
                'origin' => [
                    'url' => $url,
                    'healthcheck_path' => $path,
                ],
            ],
        ],
    ]);

    return $site;
}

test('it refuses private and metadata origins without fetching', function (string $url) {
    Http::fake();

    $result = app(OriginHealthcheckRunner::class)->run(edgeSiteWithOrigin($url));

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe(0)
        ->and($result['message'])->toBe('Origin URL is not allowed.');

    Http::assertNothingSent();
})->with([
    'http://127.0.0.1',
    'http://169.254.169.254',
    'http://10.0.0.4',
    'http://localhost',
]);

test('it fetches a public origin and does not follow redirects', function () {
    Http::fake([
        'http://1.1.1.1/*' => Http::response('ok', 200),
    ]);

    $result = app(OriginHealthcheckRunner::class)->run(edgeSiteWithOrigin('http://1.1.1.1', '/health'));

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe(200);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'http://1.1.1.1/health';
    });
});
