<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NginxConfigSyntaxTesterTest;

use App\Services\Webserver\NginxConfigSyntaxTester;
use ReflectionMethod;

test('strip log directives removes access and error log lines', function () {
    $tester = new NginxConfigSyntaxTester;
    $method = new ReflectionMethod(NginxConfigSyntaxTester::class, 'stripLogDirectivesForLocalValidation');
    $method->setAccessible(true);

    $input = <<<'NGINX'
server {
    listen 80;
    access_log /var/log/nginx/x-access.log;
    error_log /var/log/nginx/x-error.log;
    root /var/www;
}
NGINX;

    $out = $method->invoke($tester, $input);

    $this->assertStringNotContainsString('access_log', $out);
    $this->assertStringNotContainsString('error_log', $out);
    $this->assertStringContainsString('listen 80', $out);
    $this->assertStringContainsString('root /var/www', $out);
});
