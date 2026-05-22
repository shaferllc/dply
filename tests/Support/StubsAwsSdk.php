<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\AwsEksService;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Response;

/**
 * Use this trait on a test class that needs to stub AWS SDK calls without
 * touching the real network. Mirrors the role Http::fake() plays for the
 * Laravel HTTP client — but for the AWS SDK, which has its own handler
 * pipeline that ignores Http::fake.
 *
 * Wires a {@see MockHandler} into the container under `aws.eks.handler`;
 * {@see AwsEksService} checks that binding and attaches the
 * handler when present (test-only hook, no production effect).
 *
 * Usage:
 *
 *   $mock = $this->fakeAws();
 *   $this->queueAwsResult(['cluster' => ['name' => 'x', 'status' => 'ACTIVE', ...]]);
 *   $this->queueAwsResult(['nodegroups' => ['ng-1']]);
 *   // … run the SUT, which constructs AwsEksService internally
 *
 * Responses dequeue in order, so queue them in the same sequence the SUT
 * will make AWS API calls in.
 */
trait StubsAwsSdk
{
    protected ?MockHandler $awsMockHandler = null;

    protected function fakeAws(): MockHandler
    {
        $this->awsMockHandler = new MockHandler;
        app()->bind('aws.eks.handler', fn () => $this->awsMockHandler);

        return $this->awsMockHandler;
    }

    /**
     * Append a successful AWS API response. $data goes straight into the
     * {@see Result} object the SDK returns — keys match what the real EKS
     * API returns (e.g. ['cluster' => [...]] for DescribeCluster).
     *
     * @param  array<string, mixed>  $data
     */
    protected function queueAwsResult(array $data): void
    {
        $this->ensureMockHandler();
        $this->awsMockHandler->append(new Result($data));
    }

    /**
     * Append a failed AWS API response. Useful for testing 404 / throttling /
     * permission-denied paths. $awsErrorCode maps to the AWS error code the
     * SDK normally surfaces (e.g. 'ResourceNotFoundException').
     */
    protected function queueAwsError(string $awsErrorCode, string $message = 'Stubbed AWS error', int $httpStatus = 400): void
    {
        $this->ensureMockHandler();
        $this->awsMockHandler->append(function (CommandInterface $command) use ($awsErrorCode, $message, $httpStatus) {
            return new AwsException($message, $command, [
                'code' => $awsErrorCode,
                'response' => new Response($httpStatus),
            ]);
        });
    }

    private function ensureMockHandler(): void
    {
        if ($this->awsMockHandler === null) {
            throw new \LogicException('Call fakeAws() before queueing AWS responses.');
        }
    }
}
