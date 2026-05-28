<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sites;

use App\Services\Sites\ByoRepoConfigLoader;

test('byo repo config loader parses crons and deploy hooks', function (): void {
    $yaml = <<<'YAML'
redirects:
  - from: /old
    to: /new
    status: 302

crons:
  - schedule: "0 * * * *"
    command: php artisan schedule:run

deploy_hooks:
  - phase: after_clone
    script: composer install --no-dev
    timeout: 600
YAML;

    $parsed = app(ByoRepoConfigLoader::class)->parse('dply.yaml', $yaml);

    expect($parsed['config']->redirects)->toHaveCount(1)
        ->and($parsed['crons'])->toHaveCount(1)
        ->and($parsed['crons'][0]['command'])->toBe('php artisan schedule:run')
        ->and($parsed['deploy_hooks'])->toHaveCount(1)
        ->and($parsed['deploy_hooks'][0]['phase'])->toBe('after_clone')
        ->and($parsed['deploy_hooks'][0]['script'])->toStartWith(ByoRepoConfigLoader::MANAGED_HOOK_PREFIX);
});
