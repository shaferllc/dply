<?php

declare(strict_types=1);

use App\Services\Servers\ConfigFileDescriptionResolver;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerConfigFileCatalog;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\WebserverConfigDocLinks;

test('config file description resolver explains dply nginx paths', function (): void {
    $resolver = new ConfigFileDescriptionResolver(new WebserverConfigDocLinks);

    expect($resolver->hintFor('/etc/nginx/conf.d/99-dply-engine-http-cache.conf', 'nginx', 'webserver'))
        ->toContain('cache')
        ->and($resolver->hintFor('/etc/nginx/sites-available/dply-01abc-my-site', 'nginx', 'webserver'))
        ->toContain('dply-managed')
        ->and($resolver->hintFor('/etc/php/8.4/fpm/pool.d/www.conf', null, 'php'))
        ->toContain('FPM pool');
});

test('config file description resolver assigns file roles', function (): void {
    $resolver = new ConfigFileDescriptionResolver(new WebserverConfigDocLinks);

    expect($resolver->roleFor('/etc/nginx/nginx.conf', 'nginx', 'webserver'))->toBe('main')
        ->and($resolver->roleFor('/etc/nginx/sites-available/dply-01abc-my-site', 'nginx', 'webserver'))->toBe('vhost')
        ->and($resolver->roleFor('/etc/nginx/conf.d/99-dply-engine-http-cache.conf', 'nginx', 'webserver'))->toBe('cache')
        ->and($resolver->roleFor('/etc/php/8.4/fpm/pool.d/www.conf', null, 'php'))->toBe('pool')
        ->and($resolver->roleLabel('vhost'))->toBe('Vhost')
        ->and($resolver->roleLabel('main'))->toBe('Main config');
});

test('catalog attaches hints to discovered files', function (): void {
    $resolver = new ConfigFileDescriptionResolver(new WebserverConfigDocLinks);
    $catalog = new ServerConfigFileCatalog(
        app(RemoteWebserverConfigService::class),
        Mockery::mock(ServerManageSshExecutor::class),
        $resolver,
    );

    $method = new ReflectionMethod($catalog, 'fileRow');
    $method->setAccessible(true);
    $row = $method->invoke(
        $catalog,
        '/etc/nginx/conf.d/99-dply-engine-http-cache.conf',
        '99-dply-engine-http-cache.conf',
        312,
        null,
        'webserver',
        'nginx',
    );

    expect($row)->toHaveKey('hint')
        ->and($row['hint'])->toContain('cache')
        ->and($row['role'])->toBe('cache')
        ->and($row['role_label'])->toBe('Cache');
});
