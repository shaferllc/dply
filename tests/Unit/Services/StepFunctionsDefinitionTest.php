<?php

declare(strict_types=1);

namespace Tests\Unit\Services\StepFunctionsDefinitionTest;
use App\Services\Serverless\Aws\StepFunctionsDefinition;
test('it chains functions into a state machine', function () {
    $definition = json_decode(
        StepFunctionsDefinition::forSequence(['fetch', 'transform', 'store']),
        true,
    );

    expect($definition['StartAt'])->toBe('fetch');
    expect(array_keys($definition['States']))->toBe(['fetch', 'transform', 'store']);

    // Each state invokes its Lambda and threads output into the next.
    expect($definition['States']['fetch']['Next'])->toBe('transform');
    expect($definition['States']['transform']['Next'])->toBe('store');
    expect($definition['States']['store']['End'])->toBeTrue();

    expect($definition['States']['fetch']['Parameters']['FunctionName'])->toBe('fetch');
    expect($definition['States']['fetch']['Resource'])->toBe('arn:aws:states:::lambda:invoke');
    expect($definition['States']['fetch']['OutputPath'])->toBe('$.Payload');
});
test('it rejects a sequence shorter than two actions', function () {
    $this->expectException(InvalidArgumentException::class);
    StepFunctionsDefinition::forSequence(['solo']);
});
test('it rejects duplicate function names', function () {
    $this->expectException(InvalidArgumentException::class);
    StepFunctionsDefinition::forSequence(['process', 'process']);
});
