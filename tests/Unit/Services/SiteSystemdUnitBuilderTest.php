<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteSystemdUnitBuilderTest;

use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdUnitBuilder;

test('web unit is null for php runtime', function () {
    $site = new Site([
        'runtime' => 'php',
        'slug' => 'laravel-app',
        'start_command' => 'php-fpm',
        'internal_port' => 30000,
    ]);

    expect((new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply'))->toBeNull();
});
test('web unit is null for static runtime', function () {
    $site = new Site([
        'runtime' => 'static',
        'slug' => 'docs-site',
    ]);

    expect((new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply'))->toBeNull();
});
test('web unit is null when start command is empty', function () {
    $site = new Site([
        'runtime' => 'node',
        'slug' => 'no-cmd',
        'start_command' => '',
    ]);

    expect((new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply'))->toBeNull();
});
test('web unit renders unit for node site', function () {
    $site = new Site([
        'runtime' => 'node',
        'slug' => 'jobs-app',
        'start_command' => 'npm start',
        'internal_port' => 30007,
        'repository_path' => '/var/www/jobs-app',
        'deploy_strategy' => 'simple',
    ]);

    $unit = (new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply');

    expect($unit)->not->toBeNull();
    $this->assertStringContainsString('Description=Dply site jobs-app (web)', $unit);
    $this->assertStringContainsString('User=dply', $unit);
    $this->assertStringContainsString('Group=dply', $unit);
    $this->assertStringContainsString('WorkingDirectory=/var/www/jobs-app', $unit);
    $this->assertStringContainsString('Environment=PORT=30007', $unit);
    $this->assertStringContainsString('ExecStart=npm start', $unit);
    $this->assertStringContainsString('Restart=on-failure', $unit);
    $this->assertStringContainsString('After=network-online.target', $unit);
    $this->assertStringContainsString('WantedBy=multi-user.target', $unit);
});
test('web unit uses atomic release current symlink', function () {
    $site = new Site([
        'runtime' => 'python',
        'slug' => 'fastapi-svc',
        'start_command' => 'uvicorn main:app --host 0.0.0.0 --port 8000',
        'internal_port' => 30002,
        'repository_path' => '/var/www/fastapi-svc',
        'deploy_strategy' => 'atomic',
    ]);

    $unit = (new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply');

    expect($unit)->not->toBeNull();
    $this->assertStringContainsString('WorkingDirectory=/var/www/fastapi-svc/current', $unit);
});
test('web unit falls back to app port then default when internal port missing', function () {
    $site = new Site([
        'runtime' => 'node',
        'slug' => 'legacy',
        'start_command' => 'node server.js',
        'internal_port' => null,
        'app_port' => 4001,
        'repository_path' => '/var/www/legacy',
    ]);

    $unit = (new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply');

    expect($unit)->not->toBeNull();
    $this->assertStringContainsString('Environment=PORT=4001', $unit);
});
test('web unit omits port environment when no port set', function () {
    $site = new Site([
        'runtime' => 'go',
        'slug' => 'no-port',
        'start_command' => './bin/app',
        'internal_port' => null,
        'app_port' => null,
        'repository_path' => '/var/www/no-port',
    ]);

    $unit = (new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply');

    expect($unit)->not->toBeNull();
    $this->assertStringNotContainsString('Environment=PORT', $unit);
});
test('web unit falls back to var www slug when repository path unset', function () {
    $site = new Site([
        'runtime' => 'node',
        'slug' => 'autoplaced',
        'start_command' => 'node server.js',
        'internal_port' => 30001,
        'repository_path' => null,
    ]);

    $unit = (new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply');

    expect($unit)->not->toBeNull();
    $this->assertStringContainsString('WorkingDirectory=/var/www/autoplaced', $unit);
});
test('process unit renders for a worker', function () {
    $site = new Site([
        'runtime' => 'node',
        'slug' => 'queue-app',
        'repository_path' => '/var/www/queue-app',
    ]);
    $process = new SiteProcess([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'worker',
        'command' => 'npm run worker',
    ]);

    $unit = (new SiteSystemdUnitBuilder)->buildProcessUnit($site, $process, 'dply');

    expect($unit)->not->toBeNull();
    $this->assertStringContainsString('Description=Dply site queue-app (worker)', $unit);
    $this->assertStringContainsString('ExecStart=npm run worker', $unit);
    $this->assertStringNotContainsString('Environment=PORT', $unit);
});
test('process unit is null when command missing', function () {
    $site = new Site([
        'runtime' => 'node',
        'slug' => 'queue-app',
    ]);
    $process = new SiteProcess([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'web',
        'command' => null,
    ]);

    $unit = (new SiteSystemdUnitBuilder)->buildProcessUnit($site, $process, 'dply');

    expect($unit)->toBeNull();
});
test('unit names are id scoped and filesystem safe', function () {
    $site = new Site;
    $site->id = '01H77NABCDEF1234ABCD';
    $site->slug = 'app';

    $process = new SiteProcess([
        'name' => 'celery beat',
    ]);

    $builder = new SiteSystemdUnitBuilder;
    expect($builder->webUnitName($site))->toBe('dply-site-01H77NABCDEF1234ABCD.service');
    expect($builder->processUnitName($site, $process))->toBe('dply-site-01H77NABCDEF1234ABCD-celery-beat.service');
});
