<?php

declare(strict_types=1);

namespace App\Services\Serverless;

use App\Models\Server;
use App\Services\Serverless\Aws\AwsEventBridgeTriggerBackend;
use App\Services\Serverless\Aws\AwsStepFunctionsSequenceBackend;
use App\Services\Serverless\Backends\ServerlessSequenceBackend;
use App\Services\Serverless\Backends\ServerlessTriggerBackend;
use App\Services\Serverless\Backends\UnsupportedServerlessBackend;

/**
 * Resolves the trigger and sequence backends for a serverless host.
 *
 * The OpenWhisk-native features (alarm-feed triggers, sequence actions) are
 * one implementation of provider-neutral contracts; AWS Lambda will be
 * another (EventBridge schedules, Step Functions state machines). This
 * resolver dispatches on host kind so callers depend only on the interfaces.
 *
 * Until the Lambda implementations land, a Lambda host resolves to
 * {@see UnsupportedServerlessBackend}, which reports an explanatory failure
 * rather than throwing — so callers never have to special-case the host.
 */
class ServerlessBackendResolver
{
    public function __construct(
        private readonly ServerlessTriggerProvisioner $openWhiskTriggers,
        private readonly ServerlessSequenceDeployer $openWhiskSequences,
    ) {}

    public function triggerBackend(Server $server): ServerlessTriggerBackend
    {
        if ($server->isDigitalOceanFunctionsHost()) {
            return $this->openWhiskTriggers;
        }

        if ($server->isAwsLambdaHost()) {
            return AwsEventBridgeTriggerBackend::forServer($server);
        }

        return new UnsupportedServerlessBackend($this->unsupportedReason($server));
    }

    public function sequenceBackend(Server $server): ServerlessSequenceBackend
    {
        if ($server->isDigitalOceanFunctionsHost()) {
            return $this->openWhiskSequences;
        }

        if ($server->isAwsLambdaHost()) {
            return AwsStepFunctionsSequenceBackend::forServer($server);
        }

        return new UnsupportedServerlessBackend($this->unsupportedReason($server));
    }

    private function unsupportedReason(Server $server): string
    {
        return 'This host is not a serverless function host.';
    }
}
