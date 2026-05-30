<?php

declare(strict_types=1);

use App\Services\Servers\EnvoyAdminProxy;

test('envoy admin proxy normalizes and guards paths', function (): void {
    $proxy = app(EnvoyAdminProxy::class);

    expect($proxy->normalizePath('/stats/prometheus'))->toBe('stats/prometheus');
    $proxy->guardPath('stats/prometheus');

    expect(fn () => $proxy->guardPath('../etc/passwd'))->toThrow(InvalidArgumentException::class);
});

test('envoy admin proxy rewrites html root links', function (): void {
    $proxy = new class extends EnvoyAdminProxy
    {
        public function rewriteHtml(string $body, string $prefix): string
        {
            $method = new ReflectionMethod(EnvoyAdminProxy::class, 'rewriteHtmlBody');
            $method->setAccessible(true);

            return $method->invoke($this, $body, $prefix);
        }
    };

    $html = '<html><head></head><body><a href="/stats">Stats</a></body></html>';
    $rewritten = $proxy->rewriteHtml($html, 'https://app.test/servers/1/envoy/admin');

    expect($rewritten)->toContain('href="https://app.test/servers/1/envoy/admin/stats"');
});
