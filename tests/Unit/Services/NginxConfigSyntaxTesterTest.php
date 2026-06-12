<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NginxConfigSyntaxTesterTest;

use App\Services\Webserver\NginxConfigSyntaxTester;
use ReflectionMethod;

function neutralize(string $input): string
{
    $method = new ReflectionMethod(NginxConfigSyntaxTester::class, 'neutralizeServerOnlyDirectives');
    $method->setAccessible(true);

    return (string) $method->invoke(new NginxConfigSyntaxTester, $input);
}

test('neutralize blanks log directives but keeps surrounding directives', function () {
    $input = <<<'NGINX'
    server {
        listen 80;
        access_log /var/log/nginx/x-access.log;
        error_log /var/log/nginx/x-error.log;
        root /var/www;
    }
    NGINX;

    $out = neutralize($input);

    $this->assertStringNotContainsString('access_log', $out);
    $this->assertStringNotContainsString('error_log', $out);
    $this->assertStringContainsString('listen 80', $out);
    $this->assertStringContainsString('root /var/www', $out);
});

test('neutralize removes file-backed ssl directives and the ssl listen parameter', function () {
    $input = <<<'NGINX'
    server {
        listen 443 ssl;
        listen [::]:443 ssl http2;
        ssl_certificate /etc/letsencrypt/live/x/fullchain.pem;
        ssl_certificate_key /etc/letsencrypt/live/x/privkey.pem;
        ssl_protocols TLSv1.2 TLSv1.3;
        server_name x.test;
    }
    NGINX;

    $out = neutralize($input);

    // Cert/key directives (which reference missing local files) are gone...
    $this->assertStringNotContainsString('ssl_certificate', $out);
    // ...the listen port stays but without the ssl parameter (no cert required)...
    $this->assertStringContainsString('listen 443;', $out);
    $this->assertStringContainsString('listen [::]:443;', $out);
    // ...and non-file ssl directives are left untouched.
    $this->assertStringContainsString('ssl_protocols TLSv1.2 TLSv1.3;', $out);
});

test('neutralize preserves line count so error line numbers stay accurate', function () {
    $input = "server {\n    access_log /var/log/x.log;\n    ssl_certificate /tmp/x.pem;\n    listen 80;\n}";

    $out = neutralize($input);

    expect(substr_count($out, "\n"))->toBe(substr_count($input, "\n"));
});
