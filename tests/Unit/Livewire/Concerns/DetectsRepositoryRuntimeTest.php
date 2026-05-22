<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Concerns\DetectsRepositoryRuntimeTest;
function subject(): object
{
    return new class
    {
        use \App\Livewire\Concerns\DetectsRepositoryRuntime;

        function applyDetectedRuntimePrefills(): void
        {
        }

        /**
         * @param  array<string, mixed>  $detection
         * @return array<string, mixed>
         */
        function exposeServerlessDetectionToArray(array $detection, string $url, string $branch): array
        {
            return $this->serverlessDetectionToArray($detection, $url, $branch);
        }
    };
}
test('normalize to clone url expands owner name shorthand', function () {
    expect(subject()->normalizeToCloneUrl('acme/api'))->toBe('https://github.com/acme/api.git');
});
test('normalize to clone url trims surrounding slashes', function () {
    expect(subject()->normalizeToCloneUrl('/acme/api/'))->toBe('https://github.com/acme/api.git');
});
test('normalize to clone url passes full urls through', function () {
    $subject = subject();

    expect($subject->normalizeToCloneUrl('https://github.com/acme/api.git'))->toBe('https://github.com/acme/api.git');
    expect($subject->normalizeToCloneUrl('git@github.com:acme/api.git'))->toBe('git@github.com:acme/api.git');
});
test('normalize to clone url returns empty for blank input', function () {
    expect(subject()->normalizeToCloneUrl('   '))->toBe('');
});
test('serverless detection collapses unknown framework to no match', function () {
    $plan = subject()->exposeServerlessDetectionToArray(['framework' => 'unknown', 'runtime' => ''], 'https://github.com/acme/api.git', 'main');

    expect($plan['no_match'])->toBeTrue();
    expect($plan['kind'])->toBe('serverless');
    $this->assertArrayNotHasKey('runtime', $plan);
});
test('serverless detection maps a recognized action', function () {
    $plan = subject()->exposeServerlessDetectionToArray([
        'framework' => 'raw',
        'deploy_kind' => 'raw',
        'runtime' => 'php:8.3',
        'entrypoint' => 'main',
        'build_command' => 'composer install',
        'confidence' => 'high',
        'reasons' => ['Detected a raw OpenWhisk php action.'],
        'warnings' => [],
    ], 'https://github.com/acme/api.git', 'main');

    expect($plan['kind'])->toBe('serverless');
    expect($plan['framework'])->toBe('raw');
    expect($plan['runtime'])->toBe('php:8.3');
    expect($plan['entrypoint'])->toBe('main');
    expect($plan['build_command'])->toBe('composer install');
    expect($plan['confidence'])->toBe('high');
    expect($plan['version'])->toBeNull();
    expect($plan['processes'])->toBe([]);
    $this->assertArrayNotHasKey('no_match', $plan);
});
