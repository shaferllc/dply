<?php

declare(strict_types=1);

use App\Services\Servers\ApacheCustomVhostsConfig;

test('apache custom vhost render builds virtualhost block', function () {
    $config = app(ApacheCustomVhostsConfig::class);

    $rendered = $config->render('legacy-api', [
        'server_name' => 'api.example.com',
        'server_aliases' => ['www.example.com'],
        'document_root' => '/var/www/legacy/public',
        'php_socket' => '/run/php/php8.3-fpm.sock',
    ]);

    expect($rendered)->toContain('ServerName api.example.com')
        ->and($rendered)->toContain('ServerAlias www.example.com')
        ->and($rendered)->toContain('DocumentRoot /var/www/legacy/public')
        ->and($rendered)->toContain('proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/');
});

test('apache custom vhost requires server name', function () {
    $config = app(ApacheCustomVhostsConfig::class);

    expect(fn () => $config->render('legacy', [
        'server_name' => '',
        'server_aliases' => [],
        'document_root' => '/var/www/a',
        'php_socket' => '',
    ]))->toThrow(InvalidArgumentException::class);
});
