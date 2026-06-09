<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeAwareDeployStepDefaultsTest;

use App\Models\SiteDeployStep;
use App\Services\Deploy\RuntimeAwareDeployStepDefaults;

test('php laravel emits composer install then migrate then optimize', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('php', 'laravel');

    expect($steps)->toHaveCount(3);
    expect($steps[0]['step_type'])->toBe(SiteDeployStep::TYPE_COMPOSER_INSTALL);
    expect($steps[0]['phase'])->toBe(SiteDeployStep::PHASE_BUILD);
    expect($steps[1]['step_type'])->toBe(SiteDeployStep::TYPE_ARTISAN_MIGRATE);
    expect($steps[1]['phase'])->toBe(SiteDeployStep::PHASE_RELEASE);
    expect($steps[2]['step_type'])->toBe(SiteDeployStep::TYPE_ARTISAN_OPTIMIZE);
    expect($steps[2]['phase'])->toBe(SiteDeployStep::PHASE_RELEASE);
});
test('php generic omits artisan steps', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('php', null);

    expect($steps)->toHaveCount(1);
    expect($steps[0]['step_type'])->toBe(SiteDeployStep::TYPE_COMPOSER_INSTALL);
});
test('node next adds npm run build', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('node', 'next');

    expect($steps)->toHaveCount(2);
    expect($steps[0]['step_type'])->toBe(SiteDeployStep::TYPE_NPM_CI);
    expect($steps[1]['step_type'])->toBe(SiteDeployStep::TYPE_NPM_RUN);
    expect($steps[1]['custom_command'])->toBe('build');
});
test('node generic omits npm run build', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('node', 'node');

    expect($steps)->toHaveCount(1);
    expect($steps[0]['step_type'])->toBe(SiteDeployStep::TYPE_NPM_CI);
});
test('python django includes collectstatic build and migrate release', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('python', 'django');

    expect($steps)->toHaveCount(3);
    $commands = array_column($steps, 'custom_command');
    expect($commands)->toContain('pip install -r requirements.txt');
    expect($commands)->toContain('python manage.py collectstatic --noinput');
    expect($commands)->toContain('python manage.py migrate --noinput');

    // Migrate is in release phase, others in build.
    $migrate = collect($steps)->firstWhere('custom_command', 'python manage.py migrate --noinput');
    expect($migrate['phase'])->toBe(SiteDeployStep::PHASE_RELEASE);
});
test('ruby rails includes assets precompile build and db migrate release', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('ruby', 'rails');

    expect($steps)->toHaveCount(3);
    $commands = array_column($steps, 'custom_command');
    $this->assertStringContainsString('bundle install', $commands[0]);
    expect($commands)->toContain('bundle exec rails assets:precompile');
    expect($commands)->toContain('bundle exec rails db:migrate');

    $migrate = collect($steps)->firstWhere('custom_command', 'bundle exec rails db:migrate');
    expect($migrate['phase'])->toBe(SiteDeployStep::PHASE_RELEASE);
});
test('go emits single build step', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('go');

    expect($steps)->toHaveCount(1);
    expect($steps[0]['step_type'])->toBe(SiteDeployStep::TYPE_CUSTOM);
    $this->assertStringContainsString('go build', $steps[0]['custom_command']);
    expect($steps[0]['phase'])->toBe(SiteDeployStep::PHASE_BUILD);
});
test('static jekyll emits jekyll build', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('static', 'jekyll');

    expect($steps)->toHaveCount(1);
    expect($steps[0]['custom_command'])->toBe('bundle exec jekyll build');
});
test('static hugo emits hugo minify', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('static', 'hugo');

    expect($steps)->toHaveCount(1);
    expect($steps[0]['custom_command'])->toBe('hugo --minify');
});
test('static plain index html emits no steps', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('static', 'static');

    expect($steps)->toBe([]);
});
test('null runtime returns empty list', function () {
    expect((new RuntimeAwareDeployStepDefaults)->defaultsFor(null))->toBe([]);
});
test('unknown runtime returns empty list', function () {
    expect((new RuntimeAwareDeployStepDefaults)->defaultsFor('cobol'))->toBe([]);
});
test('sort order increases in declaration order', function () {
    $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('php', 'laravel');

    $orders = array_column($steps, 'sort_order');
    $sorted = $orders;
    sort($sorted);
    expect($orders)->toBe($sorted);
    expect($orders[1])->toBeGreaterThan($orders[0]);
});
