<?php

declare(strict_types=1);

namespace Tests\Unit\SitePhpRuntimeDirectivesBuilderTest;

use App\Models\Site;
use App\Services\Sites\SitePhpRuntimeDirectivesBuilder;

function siteWithPhpRuntime(?array $runtime): Site
{
    $site = new Site;
    $site->meta = $runtime === null ? [] : ['php_runtime' => $runtime];

    return $site;
}

test('emits nothing when no php runtime is configured', function () {
    $builder = new SitePhpRuntimeDirectivesBuilder;

    expect($builder->nginxDirectives(siteWithPhpRuntime(null)))->toBe('');
    expect($builder->caddyEnvDirective(siteWithPhpRuntime([])))->toBe('');
});

test('nginx emits a single PHP_VALUE param with newline-joined directives', function () {
    $out = (new SitePhpRuntimeDirectivesBuilder)->nginxDirectives(siteWithPhpRuntime([
        'memory_limit' => '512M',
        'upload_max_filesize' => '100M',
        'post_max_size' => '100M',
        'timezone' => 'UTC',
    ]));

    // Exactly one PHP_VALUE param — repeating it would override earlier values.
    expect(substr_count($out, 'PHP_VALUE'))->toBe(1);
    expect($out)->toContain('fastcgi_param PHP_VALUE "memory_limit=512M');
    expect($out)->toContain("\nupload_max_filesize=100M");
    expect($out)->toContain("\ndate.timezone=UTC");
    expect($out)->toEndWith("\";\n");
});

test('caddy wraps directives in a backtick env token', function () {
    $out = (new SitePhpRuntimeDirectivesBuilder)->caddyEnvDirective(siteWithPhpRuntime([
        'memory_limit' => '256M',
        'max_input_vars' => '5000',
    ]));

    expect($out)->toContain('env PHP_VALUE `memory_limit=256M');
    expect($out)->toContain("\nmax_input_vars=5000");
    expect($out)->toEndWith("`\n");
});

test('skips empty, null, and unsafe values', function () {
    $out = (new SitePhpRuntimeDirectivesBuilder)->nginxDirectives(siteWithPhpRuntime([
        'memory_limit' => '512M',
        'upload_max_filesize' => '',
        'post_max_size' => null,
        'max_input_vars' => '5000"; evil',
        'timezone' => 'UTC',
    ]));

    expect($out)->toContain('memory_limit=512M');
    expect($out)->toContain('date.timezone=UTC');
    expect($out)->not->toContain('upload_max_filesize');
    expect($out)->not->toContain('post_max_size');
    expect($out)->not->toContain('evil');
});

test('shorthandBytes parses php size suffixes', function () {
    expect(SitePhpRuntimeDirectivesBuilder::shorthandBytes('1G'))->toBe(1024 * 1024 * 1024);
    expect(SitePhpRuntimeDirectivesBuilder::shorthandBytes('64M'))->toBe(64 * 1024 * 1024);
    expect(SitePhpRuntimeDirectivesBuilder::shorthandBytes('512K'))->toBe(512 * 1024);
    expect(SitePhpRuntimeDirectivesBuilder::shorthandBytes('1024'))->toBe(1024);
    expect(SitePhpRuntimeDirectivesBuilder::shorthandBytes('0'))->toBe(0);
    expect(SitePhpRuntimeDirectivesBuilder::shorthandBytes(''))->toBe(0);
    expect(SitePhpRuntimeDirectivesBuilder::shorthandBytes('garbage'))->toBe(0);
});
