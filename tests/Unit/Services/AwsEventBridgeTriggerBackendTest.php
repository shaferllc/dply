<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AwsEventBridgeTriggerBackendTest;
use App\Models\FunctionAction;
use App\Services\Serverless\Aws\AwsEventBridgeTriggerBackend;
use Aws\EventBridge\EventBridgeClient;
use Aws\Lambda\LambdaClient;
use Aws\MockHandler;
use Aws\Result;
function client(string $service, MockHandler $handler): EventBridgeClient|LambdaClient
{
    $config = [
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => $handler,
    ];

    return $service === 'eventbridge' ? new EventBridgeClient($config) : new LambdaClient($config);
}
test('provision creates a rule grants permission and targets the function', function () {
    $eventBridge = new MockHandler;
    $eventBridge->append(new Result(['RuleArn' => 'arn:aws:events:us-east-1:1:rule/orders-dply-cron']));
    // putRule
    $eventBridge->append(new Result(['FailedEntryCount' => 0]));

    // putTargets
    $lambda = new MockHandler;
    $lambda->append(new Result(['Configuration' => ['FunctionArn' => 'arn:aws:lambda:us-east-1:1:function:orders']]));
    // getFunction
    $lambda->append(new Result([]));

    // addPermission
    $backend = new AwsEventBridgeTriggerBackend(
        client('eventbridge', $eventBridge),
        client('lambda', $lambda),
    );

    $action = new FunctionAction(['name' => 'orders', 'trigger' => ['cron' => '*/5 * * * *', 'enabled' => true]]);
    $result = $backend->provision($action);

    expect($result['ok'])->toBeTrue((string) $result['error']);
    expect($result['trigger'])->toBe('orders-dply-cron');
});
test('provision with no enabled schedule tears down instead of creating', function () {
    // A disabled schedule routes through remove(): removeTargets +
    // deleteRule on EventBridge, removePermission on Lambda.
    $eventBridge = new MockHandler;
    $eventBridge->append(new Result([]));
    // removeTargets
    $eventBridge->append(new Result([]));

    // deleteRule
    $lambda = new MockHandler;
    $lambda->append(new Result([]));

    // removePermission
    $backend = new AwsEventBridgeTriggerBackend(
        client('eventbridge', $eventBridge),
        client('lambda', $lambda),
    );

    $action = new FunctionAction(['name' => 'orders', 'trigger' => ['cron' => '*/5 * * * *', 'enabled' => false]]);
    $result = $backend->provision($action);

    expect($result['ok'])->toBeTrue();
    expect($result['trigger'])->toBeNull();
});
