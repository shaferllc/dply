<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use App\Models\Server;
use App\Services\Serverless\Aws\AwsEventBridgeTriggerBackend;
use App\Services\Serverless\Aws\AwsStepFunctionsSequenceBackend;
use App\Services\Serverless\Backends\ServerlessSequenceBackend;
use App\Services\Serverless\Backends\ServerlessTriggerBackend;
use App\Services\Serverless\ServerlessBackendResolver;
use App\Services\Serverless\ServerlessSequenceDeployer;
use App\Services\Serverless\ServerlessTriggerProvisioner;
use Tests\TestCase;

class ServerlessBackendResolverTest extends TestCase
{
    private function server(string $hostKind): Server
    {
        return (new Server)->forceFill(['meta' => ['host_kind' => $hostKind]]);
    }

    public function test_the_openwhisk_services_satisfy_the_backend_contracts(): void
    {
        $this->assertInstanceOf(ServerlessTriggerBackend::class, new ServerlessTriggerProvisioner);
        $this->assertInstanceOf(ServerlessSequenceBackend::class, new ServerlessSequenceDeployer);
    }

    public function test_a_digitalocean_functions_host_resolves_to_the_openwhisk_backends(): void
    {
        $resolver = app(ServerlessBackendResolver::class);
        $server = $this->server(Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS);

        $this->assertInstanceOf(ServerlessTriggerProvisioner::class, $resolver->triggerBackend($server));
        $this->assertInstanceOf(ServerlessSequenceDeployer::class, $resolver->sequenceBackend($server));
    }

    public function test_an_aws_lambda_host_resolves_to_the_aws_backends(): void
    {
        $resolver = app(ServerlessBackendResolver::class);
        $server = $this->server(Server::HOST_KIND_AWS_LAMBDA);

        $this->assertInstanceOf(AwsEventBridgeTriggerBackend::class, $resolver->triggerBackend($server));
        $this->assertInstanceOf(AwsStepFunctionsSequenceBackend::class, $resolver->sequenceBackend($server));
    }
}
