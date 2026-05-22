<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\FunctionAction;
use App\Services\Serverless\Aws\AwsEventBridgeTriggerBackend;
use Aws\EventBridge\EventBridgeClient;
use Aws\Lambda\LambdaClient;
use Aws\MockHandler;
use Aws\Result;
use Tests\TestCase;

class AwsEventBridgeTriggerBackendTest extends TestCase
{
    private function client(string $service, MockHandler $handler): EventBridgeClient|LambdaClient
    {
        $config = [
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ];

        return $service === 'eventbridge' ? new EventBridgeClient($config) : new LambdaClient($config);
    }

    public function test_provision_creates_a_rule_grants_permission_and_targets_the_function(): void
    {
        $eventBridge = new MockHandler;
        $eventBridge->append(new Result(['RuleArn' => 'arn:aws:events:us-east-1:1:rule/orders-dply-cron'])); // putRule
        $eventBridge->append(new Result(['FailedEntryCount' => 0]));                                         // putTargets

        $lambda = new MockHandler;
        $lambda->append(new Result(['Configuration' => ['FunctionArn' => 'arn:aws:lambda:us-east-1:1:function:orders']])); // getFunction
        $lambda->append(new Result([]));                                                                     // addPermission

        $backend = new AwsEventBridgeTriggerBackend(
            $this->client('eventbridge', $eventBridge),
            $this->client('lambda', $lambda),
        );

        $action = new FunctionAction(['name' => 'orders', 'trigger' => ['cron' => '*/5 * * * *', 'enabled' => true]]);
        $result = $backend->provision($action);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('orders-dply-cron', $result['trigger']);
    }

    public function test_provision_with_no_enabled_schedule_tears_down_instead_of_creating(): void
    {
        // A disabled schedule routes through remove(): removeTargets +
        // deleteRule on EventBridge, removePermission on Lambda.
        $eventBridge = new MockHandler;
        $eventBridge->append(new Result([])); // removeTargets
        $eventBridge->append(new Result([])); // deleteRule

        $lambda = new MockHandler;
        $lambda->append(new Result([]));      // removePermission

        $backend = new AwsEventBridgeTriggerBackend(
            $this->client('eventbridge', $eventBridge),
            $this->client('lambda', $lambda),
        );

        $action = new FunctionAction(['name' => 'orders', 'trigger' => ['cron' => '*/5 * * * *', 'enabled' => false]]);
        $result = $backend->provision($action);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['trigger']);
    }
}
