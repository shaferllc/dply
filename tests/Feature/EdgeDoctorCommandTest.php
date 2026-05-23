<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeDoctorCommandTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('doctor reports ok in fake edge mode', function () {
    config([
        'edge.fake.enabled' => true,
        'app.env' => 'testing',
        'edge.testing_domains' => ['edge.test'],
    ]);

    $exit = Artisan::call('dply:edge:doctor', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($payload['ok'] ?? false)->toBeTrue();
    expect($payload['mode'] ?? null)->toBe('fake');
    expect($payload['checks'] ?? [])->not->toBeEmpty();
});

test('doctor fails when production credentials missing', function () {
    config([
        'edge.fake.enabled' => false,
        'edge.r2.bucket' => '',
        'edge.r2.key' => '',
        'edge.r2.secret' => '',
        'edge.r2.endpoint' => '',
        'edge.cloudflare.account_id' => '',
        'edge.cloudflare.api_token' => '',
        'edge.cloudflare.kv_namespace_id' => '',
        'app.env' => 'production',
    ]);

    $exit = Artisan::call('dply:edge:doctor', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1);
    expect($payload['ok'] ?? true)->toBeFalse();
    expect($payload['missing'] ?? [])->not->toBeEmpty();
});

test('doctor verifies cloudflare token when configured', function () {
    config([
        'edge.fake.enabled' => false,
        'app.env' => 'production',
        'edge.r2.bucket' => 'dply-edge-artifacts',
        'edge.r2.key' => 'key',
        'edge.r2.secret' => 'secret',
        'edge.r2.endpoint' => 'https://acct.r2.cloudflarestorage.com',
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'token',
        'edge.cloudflare.kv_namespace_id' => 'kv123',
    ]);

    Http::fake([
        'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => true,
            'result' => ['status' => 'active'],
        ]),
    ]);

    $exit = Artisan::call('dply:edge:doctor', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($payload['ok'] ?? false)->toBeTrue();
});
