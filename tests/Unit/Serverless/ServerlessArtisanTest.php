<?php

declare(strict_types=1);

namespace Tests\Unit\Serverless\ServerlessArtisanTest;

use App\Modules\Serverless\Support\FunctionConfiguration;
use App\Modules\Serverless\Support\ServerlessArtisan;
use RuntimeException;

test('it parses allowlisted commands and options', function () {
    expect(ServerlessArtisan::parse('optimize'))->toBe(['optimize', []]);
    expect(ServerlessArtisan::parse('php artisan down'))->toBe(['down', []]);
    expect(ServerlessArtisan::parse('migrate --force'))->toBe(['migrate', ['--force' => true]]);
});

test('it rejects tinker and shell metacharacters', function () {
    expect(fn () => ServerlessArtisan::parse('tinker'))->toThrow(RuntimeException::class);
    expect(fn () => ServerlessArtisan::parse('migrate | cat'))->toThrow(RuntimeException::class);
});

test('hmac signatures match the injected handler', function () {
    require_once resource_path('serverless/digitalocean-functions-laravel-handler.php');

    $secret = 's3cret';
    $command = 'route:cache';

    expect(ServerlessArtisan::signature($secret, $command))
        ->toBe(dply_do_functions_artisan_signature($secret, $command));
});

test('function configuration binds durable maintenance', function () {
    $off = FunctionConfiguration::fromSiteConfig([]);
    expect($off->maintenance)->toBeFalse();
    expect($off->parameterPairs())->toContain([
        'key' => FunctionConfiguration::MAINTENANCE_PARAMETER_KEY,
        'value' => false,
    ]);

    $on = FunctionConfiguration::fromSiteConfig([
        'maintenance' => ['enabled' => true],
    ]);
    expect($on->maintenance)->toBeTrue();
    expect($on->parameterPairs())->toContain([
        'key' => FunctionConfiguration::MAINTENANCE_PARAMETER_KEY,
        'value' => true,
    ]);
});
