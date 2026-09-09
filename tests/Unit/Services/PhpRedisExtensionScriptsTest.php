<?php

declare(strict_types=1);

use App\Modules\Remediations\Services\RemediationCatalog;
use App\Services\Servers\PhpRedisExtensionScripts;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\AtomicDeployHealthChecker;
use App\Support\Sites\SiteFixers;

test('duplicate redis load warning matches a dedicated remediation', function () {
    $log = 'PHP Warning:  Module "redis" is already loaded in Unknown on line 0';

    $codes = collect(app(RemediationCatalog::class)->matchAll($log))->pluck('code')->all();

    expect($codes)->toContain('php_ext_redis_duplicate')
        ->and(collect(app(RemediationCatalog::class)->find('php_ext_redis_duplicate')['actions'] ?? [])->pluck('key'))
        ->toContain('dedupe_phpredis');

    $install = collect(app(RemediationCatalog::class)->find('php_ext_redis_missing')['actions'] ?? [])
        ->firstWhere('key', 'install_phpredis')['script'] ?? '';

    expect($install)->toContain(PhpRedisExtensionScripts::dedupeFromDetectedCli());
});

test('dedupe script keeps one redis ini and strips php.ini leftovers', function () {
    $script = PhpRedisExtensionScripts::dedupe('8.4');

    expect($script)->toContain('/etc/php/8.4')
        ->and($script)->toContain('extension')
        ->and($script)->toContain('redis')
        ->and($script)->toContain('php.ini')
        ->and($script)->toContain('conf.d')
        ->and($script)->toContain('20-redis.ini');
});

test('php install script dedupes redis after apt and phpenmod', function () {
    $manager = new class extends ServerPhpManager
    {
        public function installScript(string $version): string
        {
            return $this->installPhpScript($version);
        }
    };

    $script = $manager->installScript('8.4');

    expect($script)->toContain('php8.4-redis')
        ->and($script)->toContain(PhpRedisExtensionScripts::dedupe('8.4'));

    expect(SiteFixers::spec('install_php_redis')['command'] ?? '')
        ->toContain(PhpRedisExtensionScripts::dedupeFromDetectedCli());
});

test('deploy health diagnostics list every redis extension directive', function () {
    $source = file_get_contents((new ReflectionClass(AtomicDeployHealthChecker::class))->getFileName());
    $listing = PhpRedisExtensionScripts::detectListing();

    expect($source)->toContain('PhpRedisExtensionScripts::detectListing')
        ->and($listing)->toContain('php redis load sites')
        ->and($listing)->toContain('already loaded');
});
