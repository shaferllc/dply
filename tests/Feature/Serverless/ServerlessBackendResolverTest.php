<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessBackendResolverTest;

use App\Models\Server;
use App\Services\Serverless\Aws\AwsEventBridgeTriggerBackend;
use App\Services\Serverless\Aws\AwsStepFunctionsSequenceBackend;
use App\Services\Serverless\Backends\ServerlessSequenceBackend;
use App\Services\Serverless\Backends\ServerlessTriggerBackend;
use App\Services\Serverless\ServerlessBackendResolver;
use App\Services\Serverless\ServerlessSequenceDeployer;
use App\Services\Serverless\ServerlessTriggerProvisioner;

function server(string $hostKind): Server
{
    return (new Server)->forceFill(['meta' => ['host_kind' => $hostKind]]);
}
test('the openwhisk services satisfy the backend contracts', function () {
    expect(new ServerlessTriggerProvisioner)->toBeInstanceOf(ServerlessTriggerBackend::class);
    expect(new ServerlessSequenceDeployer)->toBeInstanceOf(ServerlessSequenceBackend::class);
});
test('a digitalocean functions host resolves to the openwhisk backends', function () {
    $resolver = app(ServerlessBackendResolver::class);
    $server = server(Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS);

    expect($resolver->triggerBackend($server))->toBeInstanceOf(ServerlessTriggerProvisioner::class);
    expect($resolver->sequenceBackend($server))->toBeInstanceOf(ServerlessSequenceDeployer::class);
});
test('an aws lambda host resolves to the aws backends', function () {
    $resolver = app(ServerlessBackendResolver::class);
    $server = server(Server::HOST_KIND_AWS_LAMBDA);

    expect($resolver->triggerBackend($server))->toBeInstanceOf(AwsEventBridgeTriggerBackend::class);
    expect($resolver->sequenceBackend($server))->toBeInstanceOf(AwsStepFunctionsSequenceBackend::class);
});
