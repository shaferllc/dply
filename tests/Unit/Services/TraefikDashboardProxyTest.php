<?php

declare(strict_types=1);

use App\Services\Servers\TraefikDashboardProxy;

test('traefik dashboard proxy resolves dashboard root', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $base = 'http://127.0.0.1:9094';

    expect($proxy->resolveTargetUrl('', $base))->toBe($base.'/dashboard/');
    expect($proxy->resolveTargetUrl('dashboard', $base))->toBe($base.'/dashboard/');
});

test('traefik dashboard proxy resolves api paths', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $base = 'http://127.0.0.1:9094';

    expect($proxy->resolveTargetUrl('api', $base))->toBe($base.'/api');
    expect($proxy->resolveTargetUrl('api/http/routers', $base))->toBe($base.'/api/http/routers');
});

test('traefik dashboard proxy resolves dashboard asset paths', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $base = 'http://127.0.0.1:9094';

    expect($proxy->resolveTargetUrl('assets/app.js', $base))->toBe($base.'/dashboard/assets/app.js');
    expect($proxy->resolveTargetUrl('assets/index.eb4a9702.js', $base))->toBe($base.'/dashboard/assets/index.eb4a9702.js');
});

test('traefik dashboard proxy tries root assets path when dashboard assets 404', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $base = 'http://127.0.0.1:9094';

    expect($proxy->resolveTargetUrlCandidates('assets/_init.2dcf31c3.js', $base))->toBe([
        $base.'/dashboard/assets/_init.2dcf31c3.js',
        $base.'/assets/_init.2dcf31c3.js',
    ]);
});

test('traefik dashboard proxy tries multiple upstream paths for chunks alias', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $base = 'http://127.0.0.1:9094';

    expect($proxy->resolveTargetUrlCandidates('assets/chunks/hacks.de068122.js', $base))->toBe([
        $base.'/dashboard/assets/_hacks.de068122.js',
        $base.'/dashboard/assets/chunks/hacks.de068122.js',
        $base.'/dashboard/assets/hacks.de068122.js',
        $base.'/assets/_hacks.de068122.js',
        $base.'/assets/chunks/hacks.de068122.js',
    ]);
});

test('traefik dashboard proxy decodes base64 curl bodies', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $method = new ReflectionMethod($proxy, 'parseCurlOutput');
    $method->setAccessible(true);

    $payload = "DPLY_HTTP_STATUS:200\nDPLY_CONTENT_TYPE:font/woff2\nDPLY_BODY_B64:".base64_encode('woff-bytes');

    $parsed = $method->invoke($proxy, $payload, 'http://127.0.0.1:9094', 0);

    expect($parsed['status'])->toBe(200)
        ->and($parsed['body'])->toBe('woff-bytes');
});

test('traefik dashboard proxy rejects path traversal', function (): void {
    $proxy = app(TraefikDashboardProxy::class);

    $proxy->guardPath('../etc/passwd');
})->throws(InvalidArgumentException::class);

test('traefik dashboard proxy rejects paths with null bytes', function (): void {
    $proxy = app(TraefikDashboardProxy::class);

    $proxy->guardPath("api\0/http");
})->throws(InvalidArgumentException::class);

test('traefik dashboard proxy rewrite avoids double-prefixed asset urls', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $prefix = 'https://dplyi.test/servers/01ksrntm0z6438cqgwhfwrs34n/traefik/dashboard';
    $method = new ReflectionMethod($proxy, 'rewriteBody');
    $method->setAccessible(true);

    $input = 'import("/dashboard/assets/_init.2dcf31c3.js")';
    $output = $method->invoke($proxy, $input, $prefix);

    expect($output)->toBe('import("'.$prefix.'/assets/_init.2dcf31c3.js")')
        ->and($output)->not->toContain('/assets/assets/');
});

test('traefik dashboard proxy rewrites entry scripts even when upstream uses text plain', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $method = new ReflectionMethod($proxy, 'shouldRewriteProxiedBody');
    $method->setAccessible(true);

    expect($method->invoke($proxy, 'assets/index.eb4a9702.js', 'text/plain', 200))->toBeTrue()
        ->and($method->invoke($proxy, 'assets/api.cb84b16a.js', 'text/plain', 200))->toBeTrue()
        ->and($method->invoke($proxy, 'assets/_init.2dcf31c3.js', 'text/plain', 200))->toBeFalse();
});

test('traefik dashboard proxy detects vite virtual boot modules omitted from go embed', function (): void {
    $proxy = app(TraefikDashboardProxy::class);

    expect($proxy->isTraefikViteVirtualBootModule('assets/_init.2dcf31c3.js'))->toBeTrue()
        ->and($proxy->isTraefikViteVirtualBootModule('assets/_hacks.de068122.js'))->toBeTrue()
        ->and($proxy->isTraefikViteVirtualBootModule('assets/chunks/init.2dcf31c3.js'))->toBeTrue()
        ->and($proxy->isTraefikViteVirtualBootModule('assets/chunks/hacks.de068122.js'))->toBeTrue()
        ->and($proxy->isTraefikViteVirtualBootModule('assets/index.eb4a9702.js'))->toBeFalse();
});

test('traefik dashboard proxy html shim rewrites root api fetches', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $prefix = 'https://dplyi.test/servers/01ksrntm0z6438cqgwhfwrs34n/traefik/dashboard';
    $method = new ReflectionMethod($proxy, 'rewriteHtmlBody');
    $method->setAccessible(true);

    $output = $method->invoke($proxy, '<html><head></head><body></body></html>', $prefix);

    expect($output)
        ->toContain('apiBase')
        ->toContain($prefix.'/api')
        ->toContain('<base href="'.$prefix.'/"');
});

test('traefik dashboard proxy leaves vite virtual module import paths unchanged in index bundle', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $prefix = 'https://dplyi.test/servers/01ksrntm0z6438cqgwhfwrs34n/traefik/dashboard';
    $method = new ReflectionMethod($proxy, 'rewriteIndexScriptBody');
    $method->setAccessible(true);

    $output = $method->invoke(
        $proxy,
        'import("./_hacks.de068122.js");import("./_init.2dcf31c3.js");',
        $prefix,
    );

    expect($output)
        ->toContain('import("./_hacks.de068122.js")')
        ->toContain('import("./_init.2dcf31c3.js")');
});

test('traefik dashboard proxy rewrite fixes axios api base url', function (): void {
    $proxy = app(TraefikDashboardProxy::class);
    $prefix = 'https://dplyi.test/servers/01ksrntm0z6438cqgwhfwrs34n/traefik/dashboard';
    $method = new ReflectionMethod($proxy, 'rewriteApiScriptBody');
    $method->setAccessible(true);

    $output = $method->invoke($proxy, 'baseURL:"/api",timeout:0', $prefix);

    expect($output)->toBe('baseURL:"'.$prefix.'/api",timeout:0');
});
