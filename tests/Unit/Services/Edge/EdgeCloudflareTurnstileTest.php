<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\EdgeCloudflareTurnstileTest;

use App\Modules\Edge\Services\EdgeCloudflareClient;
use Illuminate\Support\Facades\Http;

test('edge cloudflare client creates turnstile widgets', function () {
    config([
        'edge.cloudflare.account_id' => 'acct_test',
        'edge.cloudflare.api_token' => 'token_test',
    ]);

    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct_test/challenges/widgets' => Http::response([
            'success' => true,
            'result' => [
                'sitekey' => '0x4AAAA-site',
                'secret' => '0x4AAAA-secret',
                'mode' => 'managed',
                'name' => 'dply-edge-demo',
                'domains' => ['on-dply.site'],
            ],
        ], 200),
    ]);

    $widget = EdgeCloudflareClient::fromConfig()->createTurnstileWidget(
        'dply-edge-demo',
        ['on-dply.site', 'demo.on-dply.site'],
        'managed',
    );

    expect($widget['sitekey'])->toBe('0x4AAAA-site')
        ->and($widget['secret'])->toBe('0x4AAAA-secret')
        ->and($widget['domains'])->toContain('on-dply.site');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct_test/challenges/widgets'
            && $request['name'] === 'dply-edge-demo'
            && $request['mode'] === 'managed'
            && in_array('on-dply.site', $request['domains'], true);
    });
});
