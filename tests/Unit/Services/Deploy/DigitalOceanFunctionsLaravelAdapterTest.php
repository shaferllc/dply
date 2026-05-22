<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\DigitalOceanFunctionsLaravelAdapterTest;
use App\Services\Deploy\DigitalOceanFunctionsLaravelAdapter;
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/do-fn-laravel-'.uniqid();
    mkdir($this->dir);
});
afterEach(function () {
    foreach (['composer.json', 'index.php', 'artisan', 'bootstrap/app.php'] as $file) {
        @unlink($this->dir.'/'.$file);
    }
    @rmdir($this->dir.'/bootstrap');
    @rmdir($this->dir);
});
test('plan detects laravel via composer json', function () {
    file_put_contents($this->dir.'/composer.json', json_encode([
        'require' => ['laravel/framework' => '^13.0'],
    ]));

    $plan = (new DigitalOceanFunctionsLaravelAdapter)->plan($this->dir);

    expect($plan['laravel'])->toBeTrue();
    expect($plan['handler'])->toBe('index.php');
    expect($plan['function'])->toBe('main');
});
test('plan detects laravel via artisan and bootstrap', function () {
    mkdir($this->dir.'/bootstrap');
    file_put_contents($this->dir.'/artisan', '#!/usr/bin/env php');
    file_put_contents($this->dir.'/bootstrap/app.php', '<?php');

    expect((new DigitalOceanFunctionsLaravelAdapter)->plan($this->dir)['laravel'])->toBeTrue();
});
test('plan is false for a non laravel repo', function () {
    file_put_contents($this->dir.'/composer.json', json_encode([
        'require' => ['monolog/monolog' => '^3.0'],
    ]));

    expect((new DigitalOceanFunctionsLaravelAdapter)->plan($this->dir)['laravel'])->toBeFalse();
});
test('inject writes the handler into a laravel repo', function () {
    file_put_contents($this->dir.'/composer.json', json_encode([
        'require' => ['laravel/framework' => '^13.0'],
    ]));

    $result = (new DigitalOceanFunctionsLaravelAdapter)->inject($this->dir);

    expect($result['ran'])->toBeTrue();
    expect($this->dir.'/index.php')->toBeFile();
    $handler = (string) file_get_contents($this->dir.'/index.php');
    $this->assertStringContainsString('function main(array $args)', $handler);
    $this->assertStringContainsString('__ow_method', $handler);
});
test('inject is a no op for a non laravel repo', function () {
    file_put_contents($this->dir.'/composer.json', json_encode(['require' => []]));

    $result = (new DigitalOceanFunctionsLaravelAdapter)->inject($this->dir);

    expect($result['ran'])->toBeFalse();
    $this->assertFileDoesNotExist($this->dir.'/index.php');
});
test('the handler stub is syntactically valid php', function () {
    $stub = (new DigitalOceanFunctionsLaravelAdapter)->stubPath();
    expect($stub)->toBeFile();

    exec('php -l '.escapeshellarg($stub).' 2>&1', $output, $code);
    expect($code)->toBe(0, implode("\n", $output));
});
