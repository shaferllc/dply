<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\Sites\SitePhpCliGuard;

test('pins deploy php to the site version and installs it when missing', function () {
    $site = new Site([
        'runtime' => 'php',
        'runtime_version' => '8.4',
        'type' => SiteType::Php,
    ]);

    $prefix = app(SitePhpCliGuard::class)->prefix($site);

    expect($prefix)->toContain('/usr/bin/php8.4')
        ->and($prefix)->toContain('php8.4-cli')
        ->and($prefix)->toContain('$HOME/.dply/bin/php')
        ->and($prefix)->toContain('ondrej/sury');
});

test('emits nothing for a non-php site', function () {
    $site = new Site([
        'runtime' => 'node',
        'runtime_version' => '22',
        'type' => SiteType::Node,
    ]);

    expect(app(SitePhpCliGuard::class)->prefix($site))->toBe('');
});
