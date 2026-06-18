<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services\Aws;

use App\Models\FunctionAction;
use App\Models\Server;
use App\Services\Deploy\ServerlessProviders\Aws\AwsLambdaClientOptions;
use App\Modules\Serverless\Services\Backends\ServerlessTriggerBackend;
use Aws\EventBridge\EventBridgeClient;
use Aws\Exception\AwsException;
use Aws\Lambda\LambdaClient;
use Throwable;

/**
 * AWS implementation of {@see ServerlessTriggerBackend} — the Lambda
 * counterpart to the OpenWhisk alarm-feed provisioner.
 *
 * A scheduled action becomes an EventBridge rule whose `ScheduleExpression`
 * is the cron, with the Lambda function as its target. dply also grants
 * EventBridge permission to invoke the function. Standard cron is translated
 * to EventBridge's dialect by {@see EventBridgeCronExpression}.
 */
class AwsEventBridgeTriggerBackend implements ServerlessTriggerBackend
{
    public function __construct(
        private readonly EventBridgeClient $eventBridge,
        private readonly LambdaClient $lambda,
    ) {}

    /**
     * Build a backend with AWS clients configured for a Lambda host's
     * region and credentials.
     */
    public static function forServer(Server $server): self
    {
        $config = AwsLambdaClientOptions::resolve(
            (string) (data_get($server->meta, 'aws_lambda.region') ?: $server->region ?: 'us-east-1'),
            [
                'credentials' => is_array($server->providerCredential?->credentials)
                    ? $server->providerCredential->credentials
                    : [],
                'project' => ['settings' => ['aws_region' => (string) data_get($server->meta, 'aws_lambda.region', '')]],
            ],
        )['client_config'];

        return new self(new EventBridgeClient($config), new LambdaClient($config));
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function provision(FunctionAction $action): array
    {
        $cron = $this->cronExpression($action);
        if ($cron === null) {
            return $this->remove($action) + ['trigger' => null];
        }

        $functionName = $this->functionName($action);
        $ruleName = $functionName.'-dply-cron';

        try {
            $rule = $this->eventBridge->putRule([
                'Name' => $ruleName,
                'ScheduleExpression' => EventBridgeCronExpression::fromStandardCron($cron),
                'State' => 'ENABLED',
                'Description' => 'dply scheduled trigger',
            ]);

            $function = $this->lambda->getFunction(['FunctionName' => $functionName]);
            $functionArn = (string) data_get($function->toArray(), 'Configuration.FunctionArn');

            // Permit EventBridge to invoke the function. A repeat call is a
            // ResourceConflictException — already permitted, which is fine.
            try {
                $this->lambda->addPermission([
                    'FunctionName' => $functionName,
                    'StatementId' => $ruleName,
                    'Action' => 'lambda:InvokeFunction',
                    'Principal' => 'events.amazonaws.com',
                    'SourceArn' => (string) data_get($rule->toArray(), 'RuleArn'),
                ]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceConflictException') {
                    throw $e;
                }
            }

            $this->eventBridge->putTargets([
                'Rule' => $ruleName,
                'Targets' => [['Id' => $functionName, 'Arn' => $functionArn]],
            ]);
        } catch (AwsException $e) {
            return ['ok' => false, 'error' => 'AWS EventBridge: '.$e->getAwsErrorMessage(), 'trigger' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'trigger' => null];
        }

        return ['ok' => true, 'error' => null, 'trigger' => $ruleName];
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function remove(FunctionAction $action): array
    {
        $functionName = $this->functionName($action);
        $ruleName = $functionName.'-dply-cron';

        try {
            try {
                $this->eventBridge->removeTargets(['Rule' => $ruleName, 'Ids' => [$functionName]]);
                $this->eventBridge->deleteRule(['Name' => $ruleName]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                    throw $e;
                }
            }

            try {
                $this->lambda->removePermission(['FunctionName' => $functionName, 'StatementId' => $ruleName]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                    throw $e;
                }
            }
        } catch (AwsException $e) {
            return ['ok' => false, 'error' => 'AWS EventBridge: '.$e->getAwsErrorMessage()];
        }

        return ['ok' => true, 'error' => null];
    }

    private function cronExpression(FunctionAction $action): ?string
    {
        $trigger = ($action->trigger );
        $cron = trim((string) ($trigger['cron'] ?? ''));

        return ($cron !== '' && ($trigger['enabled'] ?? false) === true) ? $cron : null;
    }

    private function functionName(FunctionAction $action): string
    {
        return trim((string) $action->name) !== '' ? (string) $action->name : (string) $action->id;
    }
}
