<?php

declare(strict_types=1);

use App\Support\Servers\CaddyAdminUrl;

test('caddy admin url resolves listen directives', function (string $listen, ?string $expected): void {
    expect(CaddyAdminUrl::fromListenDirective($listen))->toBe($expected);
})->with([
    ['localhost:2019', 'http://127.0.0.1:2019'],
    ['127.0.0.1:2019', 'http://127.0.0.1:2019'],
    ['off', null],
    ['', null],
]);

test('caddy admin url reads loaded config admin block', function (): void {
    $url = CaddyAdminUrl::fromLoadedConfig([
        'admin' => ['listen' => '127.0.0.1:2019'],
    ]);

    expect($url)->toBe('http://127.0.0.1:2019');
});
