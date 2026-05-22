<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\FunctionAction;
use App\Services\Serverless\Aws\AwsStepFunctionsSequenceBackend;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sfn\SfnClient;
use Tests\TestCase;

class AwsStepFunctionsSequenceBackendTest extends TestCase
{
    private function sfn(MockHandler $handler): SfnClient
    {
        return new SfnClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]);
    }

    private function sequence(): FunctionAction
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

    public function test_deploy_creates_a_state_machine(): void
    {
        $handler = new MockHandler;
        $handler->append(new Result(['stateMachineArn' => 'arn:aws:states:us-east-1:1:stateMachine:pipeline']));

        $backend = new AwsStepFunctionsSequenceBackend(
            $this->sfn($handler),
            'arn:aws:iam::123456789012:role/dply-sfn',
        );

        $result = $backend->deploy($this->sequence());

        $this->assertTrue($result['ok'], (string) $result['error']);
    }

    public function test_deploy_fails_without_an_execution_role(): void
    {
        $backend = new AwsStepFunctionsSequenceBackend($this->sfn(new MockHandler), '');

        $result = $backend->deploy($this->sequence());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('execution role', (string) $result['error']);
    }

    public function test_deploy_rejects_a_non_sequence_action(): void
    {
        $backend = new AwsStepFunctionsSequenceBackend(
            $this->sfn(new MockHandler),
            'arn:aws:iam::123456789012:role/dply-sfn',
        );

        $code = new FunctionAction(['name' => 'fetch', 'kind' => FunctionAction::KIND_CODE]);
        $result = $backend->deploy($code);

        $this->assertFalse($result['ok']);
    }
}
