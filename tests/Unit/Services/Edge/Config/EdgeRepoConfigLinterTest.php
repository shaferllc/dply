<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\Config;

use App\Services\Edge\Config\EdgeRepoConfigLinter;
use App\Services\Edge\Config\EdgeRepoConfigLoader;

uses()->group('edge');

beforeEach(function () {
    $this->linter = new EdgeRepoConfigLinter(new EdgeRepoConfigLoader);
});

test('lint passes for valid yaml config', function () {
    $yaml = <<<'YAML'
build:
  command: npm run build
  output: dist
redirects:
  - from: /old/*
    to: /new/:splat
    status: 301
YAML;

    $result = $this->linter->lintContent('dply.yaml', $yaml);

    expect($result['ok'])->toBeTrue()
        ->and($result['errors'])->toBe([])
        ->and($result['summary']['redirects'])->toBe(1)
        ->and($result['summary']['build_keys'])->toContain('command');
});

test('lint fails on yaml parse errors', function () {
    $result = $this->linter->lintContent('dply.yaml', "build:\n  command: [\n");

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

test('lint records non-fatal rule warnings separately from errors', function () {
    $yaml = <<<'YAML'
redirects:
  - from: ""
    to: /nope
YAML;

    $result = $this->linter->lintContent('dply.yaml', $yaml);

    expect($result['ok'])->toBeTrue()
        ->and($result['warnings'])->not->toBeEmpty()
        ->and($result['errors'])->toBe([]);
});

test('lint passes when no config file exists in directory', function () {
    $dir = sys_get_temp_dir().'/dply-lint-empty-'.uniqid('', true);
    mkdir($dir);

    try {
        $result = $this->linter->lintDirectory($dir);
    } finally {
        @rmdir($dir);
    }

    expect($result['ok'])->toBeTrue()
        ->and($result['source_path'])->toBeNull();
});

test('lint fails when config file exceeds size limit', function () {
    $dir = sys_get_temp_dir().'/dply-lint-large-'.uniqid('', true);
    mkdir($dir);
    $path = $dir.'/dply.yaml';
    file_put_contents($path, str_repeat('x', 65 * 1024));

    try {
        $result = $this->linter->lintDirectory($dir);
    } finally {
        @unlink($path);
        @rmdir($dir);
    }

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});
