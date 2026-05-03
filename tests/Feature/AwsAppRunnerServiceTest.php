<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\AwsAppRunnerService;
use Aws\AppRunner\AppRunnerClient;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AwsAppRunnerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_constructor_throws_when_credentials_missing(): void
    {
        $cred = $this->credentialWithoutKey();

        $this->expectException(\InvalidArgumentException::class);
        new AwsAppRunnerService($cred);
    }

    public function test_create_service_returns_arn_and_url(): void
    {
        $client = Mockery::mock(AppRunnerClient::class);
        $client->shouldReceive('createService')
            ->once()
            ->with(Mockery::on(function (array $args): bool {
                return $args['ServiceName'] === 'api-acme'
                    && $args['SourceConfiguration']['ImageRepository']['ImageIdentifier'] === 'public.ecr.aws/acme/api:v1'
                    && $args['SourceConfiguration']['ImageRepository']['ImageRepositoryType'] === 'ECR_PUBLIC'
                    && $args['SourceConfiguration']['ImageRepository']['ImageConfiguration']['Port'] === '8080'
                    && $args['SourceConfiguration']['ImageRepository']['ImageConfiguration']['RuntimeEnvironmentVariables'] === ['APP_ENV' => 'production'];
            }))
            ->andReturn(new Result([
                'Service' => [
                    'ServiceArn' => 'arn:aws:apprunner:us-east-1:1234:service/api-acme/abc',
                    'ServiceUrl' => 'api-acme.us-east-1.awsapprunner.com',
                ],
            ]));

        $service = $this->service($client);
        $result = $service->createService(
            serviceName: 'api-acme',
            image: 'public.ecr.aws/acme/api:v1',
            port: 8080,
            envVars: ['APP_ENV' => 'production'],
        );

        $this->assertSame('arn:aws:apprunner:us-east-1:1234:service/api-acme/abc', $result['service_arn']);
        $this->assertSame('https://api-acme.us-east-1.awsapprunner.com', $result['service_url']);
    }

    public function test_create_service_classifies_private_ecr_image(): void
    {
        $client = Mockery::mock(AppRunnerClient::class);
        $client->shouldReceive('createService')
            ->once()
            ->with(Mockery::on(function (array $args): bool {
                return $args['SourceConfiguration']['ImageRepository']['ImageRepositoryType'] === 'ECR';
            }))
            ->andReturn(new Result(['Service' => ['ServiceArn' => 'arn:aws:apprunner:test']]));

        $this->service($client)->createService(
            'api',
            '1234.dkr.ecr.us-east-1.amazonaws.com/acme/api:v1',
            8080,
        );
    }

    public function test_describe_service_returns_payload(): void
    {
        $client = Mockery::mock(AppRunnerClient::class);
        $client->shouldReceive('describeService')
            ->with(['ServiceArn' => 'arn:test'])
            ->andReturn(new Result(['Service' => ['Status' => 'RUNNING']]));

        $this->assertSame(['Status' => 'RUNNING'], $this->service($client)->describeService('arn:test'));
    }

    public function test_start_deployment_returns_operation_id(): void
    {
        $client = Mockery::mock(AppRunnerClient::class);
        $client->shouldReceive('startDeployment')
            ->with(['ServiceArn' => 'arn:test'])
            ->andReturn(new Result(['OperationId' => 'op-12345']));

        $this->assertSame(['operation_id' => 'op-12345'], $this->service($client)->startDeployment('arn:test'));
    }

    public function test_update_image_calls_update_service(): void
    {
        $client = Mockery::mock(AppRunnerClient::class);
        $client->shouldReceive('updateService')
            ->once()
            ->with(Mockery::on(function (array $args): bool {
                return $args['ServiceArn'] === 'arn:test'
                    && $args['SourceConfiguration']['ImageRepository']['ImageIdentifier'] === 'public.ecr.aws/acme/api:v2';
            }));

        $this->service($client)->updateImage('arn:test', 'public.ecr.aws/acme/api:v2', 8080);
    }

    public function test_delete_service_calls_delete(): void
    {
        $client = Mockery::mock(AppRunnerClient::class);
        $client->shouldReceive('deleteService')
            ->once()
            ->with(['ServiceArn' => 'arn:test']);

        $this->service($client)->deleteService('arn:test');
    }

    public function test_list_services_returns_array(): void
    {
        $client = Mockery::mock(AppRunnerClient::class);
        $client->shouldReceive('listServices')
            ->andReturn(new Result(['ServiceSummaryList' => [
                ['ServiceArn' => 'arn:1', 'ServiceName' => 'a'],
                ['ServiceArn' => 'arn:2', 'ServiceName' => 'b'],
            ]]));

        $list = $this->service($client)->listServices();
        $this->assertCount(2, $list);
        $this->assertSame('a', $list[0]['ServiceName']);
    }

    public function test_validate_credentials_calls_list_services(): void
    {
        $client = Mockery::mock(AppRunnerClient::class);
        $client->shouldReceive('listServices')
            ->once()
            ->with(['MaxResults' => 1])
            ->andReturn(new Result(['ServiceSummaryList' => []]));

        $this->service($client)->validateCredentials();
    }

    public function test_get_regions_returns_known_apprunner_set(): void
    {
        $regions = AwsAppRunnerService::getRegions();
        $slugs = array_column($regions, 'slug');
        $this->assertContains('us-east-1', $slugs);
        $this->assertContains('eu-west-1', $slugs);
        $this->assertContains('ap-northeast-1', $slugs);
    }

    private function service(AppRunnerClient $client): AwsAppRunnerService
    {
        return (new AwsAppRunnerService($this->credential()))->withClient($client);
    }

    private function credential(): ProviderCredential
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        return ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'aws_app_runner',
            'name' => 'Test',
            'credentials' => [
                'access_key_id' => 'AKIAFAKE',
                'secret_access_key' => 'fake-secret',
                'region' => 'us-east-1',
            ],
        ]);
    }

    private function credentialWithoutKey(): ProviderCredential
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        return ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'aws_app_runner',
            'name' => 'Test',
            'credentials' => ['access_key_id' => '', 'secret_access_key' => ''],
        ]);
    }
}
