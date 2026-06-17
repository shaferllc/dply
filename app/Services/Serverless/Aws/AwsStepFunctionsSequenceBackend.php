<?php

declare(strict_types=1);

namespace App\Services\Serverless\Aws;

use App\Models\FunctionAction;
use App\Models\Server;
use App\Services\Deploy\ServerlessProviders\Aws\AwsLambdaClientOptions;
use App\Services\Serverless\Backends\ServerlessSequenceBackend;
use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;
use Throwable;

/**
 * AWS implementation of {@see ServerlessSequenceBackend} — the Lambda
 * counterpart to the OpenWhisk sequence deployer.
 *
 * A dply sequence becomes a Step Functions state machine whose definition
 * ({@see StepFunctionsDefinition}) chains the component Lambdas. Step
 * Functions needs an IAM execution role allowed to invoke those functions;
 * its ARN is read from the host's `meta.aws_lambda.state_machine_role_arn`.
 */
class AwsStepFunctionsSequenceBackend implements ServerlessSequenceBackend
{
    public function __construct(
        private readonly SfnClient $stepFunctions,
        private readonly string $roleArn,
    ) {}

    /**
     * Build a backend with an Sfn client + execution role for a Lambda host.
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

        return new self(
            new SfnClient($config),
            (string) data_get($server->meta, 'aws_lambda.state_machine_role_arn', ''),
        );
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function deploy(FunctionAction $sequence): array
    {
        if (! $sequence->isSequence()) {
            return ['ok' => false, 'error' => 'This action is not a sequence.'];
        }
        if ($this->roleArn === '') {
            return ['ok' => false, 'error' => 'Configure an AWS Step Functions execution role for this host before deploying a sequence.'];
        }

        try {
            $definition = StepFunctionsDefinition::forSequence($this->componentNames($sequence));
            $name = (string) $sequence->name;

            try {
                $this->stepFunctions->createStateMachine([
                    'name' => $name,
                    'definition' => $definition,
                    'roleArn' => $this->roleArn,
                ]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'StateMachineAlreadyExists') {
                    throw $e;
                }
                // Already exists — update it in place.
                $this->stepFunctions->updateStateMachine([
                    'stateMachineArn' => $this->stateMachineArn($name),
                    'definition' => $definition,
                    'roleArn' => $this->roleArn,
                ]);
            }
        } catch (AwsException $e) {
            return ['ok' => false, 'error' => 'AWS Step Functions: '.$e->getAwsErrorMessage()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @return list<string>
     */
    private function componentNames(FunctionAction $sequence): array
    {
        $components = ($sequence->components );

        return array_values(array_filter(array_map(
            static fn (mixed $component): string => is_array($component) ? trim((string) ($component['name'] ?? '')) : '',
            $components,
        ), static fn (string $name): bool => $name !== ''));
    }

    /**
     * Construct the state-machine ARN. The AWS account id is taken from the
     * execution role ARN (`arn:aws:iam::{account}:role/...`).
     */
    private function stateMachineArn(string $name): string
    {
        $account = explode(':', $this->roleArn)[4] ?? '';

        return 'arn:aws:states:'.$this->stepFunctions->getRegion().':'.$account.':stateMachine:'.$name;
    }
}
