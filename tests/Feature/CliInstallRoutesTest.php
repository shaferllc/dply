<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cli install script is served with injected base url', function (): void {
    config(['app.url' => 'https://dplyi.test']);

    $response = $this->get(route('cli.install'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    expect($response->getContent())
        ->toContain('#!/usr/bin/env bash')
        ->toContain('https://dplyi.test')
        ->toContain('tarball')
        ->not->toContain('__DPLY_DEFAULT_BASE_URL__');
});

test('cli version json is served', function (): void {
    $response = $this->get(route('cli.version'));

    $response->assertOk();
    $response->assertJsonPath('name', '@dply/cli');
    $response->assertJsonStructure(['version', 'install_url', 'package_url']);
});

test('cli package tarball is served', function (): void {
    $response = $this->get(route('cli.package'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/gzip');

    $body = $response->getContent();
    expect($body)->not->toBeEmpty();

    $tmp = tempnam(sys_get_temp_dir(), 'dply-cli-test-');
    file_put_contents($tmp, $body);
    $listing = shell_exec('tar -tzf '.escapeshellarg($tmp).' 2>/dev/null') ?: '';
    unlink($tmp);

    expect($listing)->toContain('package/package.json');
    expect($listing)->toContain('package/src/instance-defaults.json');
});

test('cli package tarball bakes app url as default base', function (): void {
    config([
        'app.url' => 'https://dplyi.test',
        'cli.default_base_url' => 'https://dplyi.test',
    ]);

    $response = $this->get(route('cli.package'));
    $response->assertOk();

    $tmp = tempnam(sys_get_temp_dir(), 'dply-cli-defaults-');
    file_put_contents($tmp, $response->getContent());
    $extractDir = sys_get_temp_dir().'/dply-cli-defaults-'.uniqid('', true);
    mkdir($extractDir);
    exec('tar -xzf '.escapeshellarg($tmp).' -C '.escapeshellarg($extractDir));
    unlink($tmp);

    $defaultsPath = $extractDir.'/package/src/instance-defaults.json';
    expect(is_file($defaultsPath))->toBeTrue();
    /** @var array{baseUrl?: string} $defaults */
    $defaults = json_decode((string) file_get_contents($defaultsPath), true, 512, JSON_THROW_ON_ERROR);
    expect($defaults['baseUrl'] ?? null)->toBe('https://dplyi.test');
});
