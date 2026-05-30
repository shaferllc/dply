<?php

declare(strict_types=1);

use App\Services\Servers\NginxCustomHostsConfig;

test('nginx custom host render builds server block', function () {
    $config = app(NginxCustomHostsConfig::class);

    $rendered = $config->render('legacy-api', [
        'server_names' => ['api.example.com', 'www.example.com'],
        'listen' => ['80', '[::]:80'],
        'root' => '/var/www/legacy/public',
        'upstream' => 'unix:/run/php/php8.3-fpm.sock',
    ]);

    expect($rendered)->toContain('server_name api.example.com www.example.com')
        ->and($rendered)->toContain('root /var/www/legacy/public')
        ->and($rendered)->toContain('fastcgi_pass unix:/run/php/php8.3-fpm.sock');
});

test('nginx custom host requires server names', function () {
    $config = app(NginxCustomHostsConfig::class);

    expect(fn () => $config->render('legacy', [
        'server_names' => [],
        'listen' => ['80'],
        'root' => '/var/www/a',
        'upstream' => '',
    ]))->toThrow(InvalidArgumentException::class);
});
