<?php


namespace Tests\Unit\Services\Deploy\BrefInjectorTest;
use App\Services\Deploy\BrefInjector;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/bref-injector-'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    @unlink($this->dir.'/composer.json');
    @rmdir($this->dir);
});

function writeComposer(string $dir, array $composer): void
{
    file_put_contents($dir.'/composer.json', json_encode($composer));
}

test('laravel app plans bref and the laravel bridge', function () {
    writeComposer($this->dir, ['require' =>['laravel/framework' => '^12.0', 'php' => '^8.3']]);

    $plan = (new BrefInjector)->plan($this->dir);

    expect($plan['php'])->toBeTrue();
    expect($plan['framework'])->toBe('laravel');
    expect($plan['packages'])->toBe(['bref/bref', 'bref/laravel-bridge']);
    expect($plan['handler'])->toBe('public/index.php');
});

test('plain php app plans only bref', function () {
    writeComposer($this->dir, ['require' =>['php' => '^8.3', 'guzzlehttp/guzzle' => '^7.0']]);

    $plan = (new BrefInjector)->plan($this->dir);

    expect($plan['framework'])->toBe('php');
    expect($plan['packages'])->toBe(['bref/bref']);
});

test('directory without composer json is not php', function () {
    $plan = (new BrefInjector)->plan($this->dir);

    expect($plan['php'])->toBeFalse();
    expect($plan['packages'])->toBe([]);
});

test('already present bref packages are not re added', function () {
    writeComposer($this->dir, ['require' =>[
        'laravel/framework' => '^12.0',
        'bref/bref' => '^2.1',
        'bref/laravel-bridge' => '^2.0',
    ]]);

    $plan = (new BrefInjector)->plan($this->dir);

    expect($plan['packages'])->toBe([]);
});

test('laravel app already carrying base bref still gets the bridge', function () {
    writeComposer($this->dir, ['require' =>[
        'laravel/framework' => '^12.0',
        'bref/bref' => '^2.1',
    ]]);

    $plan = (new BrefInjector)->plan($this->dir);

    expect($plan['packages'])->toBe(['bref/laravel-bridge']);
});

test('inject is a no op when nothing to add', function () {
    writeComposer($this->dir, ['require' =>[
        'laravel/framework' => '^12.0',
        'bref/bref' => '^2.1',
        'bref/laravel-bridge' => '^2.0',
    ]]);

    $result = (new BrefInjector)->inject($this->dir);

    expect($result['ran'])->toBeFalse();
});