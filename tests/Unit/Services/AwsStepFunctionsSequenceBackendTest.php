<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AwsStepFunctionsSequenceBackendTest;
use App\Models\FunctionAction;
use App\Services\Serverless\Aws\AwsStepFunctionsSequenceBackend;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sfn\SfnClient;
function sfn(MockHandler $handler): SfnClient
{
    return new SfnClient([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => $handler,
    ]);
}
function sequence(): FunctionAction
{
    return new FunctionAction([
        'name' => 'pipeline',
        'kind' => FunctionAction::KIND_SEQUENCE,
        'components' => [
            ['id' => '1', 'name' => 'fetch'],
            ['id' => '2', 'name' => 'transform'],
        ],
    ]);
}
test('deploy creates a state machine', function () {
    $handler = new MockHandler;
    $handler->append(new Result(['stateMachineArn' => 'arn:aws:states:us-east-1:1:stateMachine:pipeline']));

    $backend = new AwsStepFunctionsSequenceBackend(
        sfn($handler),
        'arn:aws:iam::123456789012:role/dply-sfn',
    );

    $result = $backend->deploy(sequence());

    expect($result['ok'])->toBeTrue((string) $result['error']);
});
test('deploy fails without an execution role', function () {
    $backend = new AwsStepFunctionsSequenceBackend(sfn(new MockHandler), '');

    $result = $backend->deploy(sequence());

    expect($result['ok'])->toBeFalse();
    $this->assertStringContainsString('execution role', (string) $result['error']);
});
test('deploy rejects a non sequence action', function () {
    $backend = new AwsStepFunctionsSequenceBackend(
        sfn(new MockHandler),
        'arn:aws:iam::123456789012:role/dply-sfn',
    );

    $code = new FunctionAction(['name' => 'fetch', 'kind' => FunctionAction::KIND_CODE]);
    $result = $backend->deploy($code);

    expect($result['ok'])->toBeFalse();
});
