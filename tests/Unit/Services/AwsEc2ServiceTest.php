<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AwsEc2ServiceTest;

use App\Models\ProviderCredential;
use App\Services\AwsEc2Service;
use Aws\Ec2\Ec2Client;
use Aws\Result;
use Aws\Ssm\SsmClient;
use Mockery;

test('constructor throws when credentials missing', function () {
    $credential = new ProviderCredential([
        'provider' => 'aws',
        'credentials' => ['access_key_id' => '', 'secret_access_key' => ''],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    new AwsEc2Service($credential);
});

test('resolve default image id uses configured ami when set', function () {
    config(['services.aws.default_image' => 'ami-configured']);

    $service = service(ec2Client: Mockery::mock(Ec2Client::class));

    expect($service->resolveDefaultImageId())->toBe('ami-configured');
});

test('resolve default image id reads ubuntu ssm parameter when unset', function () {
    config(['services.aws.default_image' => null]);

    $ssm = Mockery::mock(SsmClient::class);
    $ssm->shouldReceive('getParameter')
        ->once()
        ->with(Mockery::on(fn (array $args): bool => str_contains($args['Name'], 'ubuntu/server/24.04')))
        ->andReturn(new Result(['Parameter' => ['Value' => 'ami-from-ssm']]));

    $service = service(ec2Client: Mockery::mock(Ec2Client::class), ssmClient: $ssm);

    expect($service->resolveDefaultImageId())->toBe('ami-from-ssm');
});

test('resolve provision security group id returns configured group', function () {
    config(['services.aws.security_group_id' => 'sg-custom']);

    $service = service(ec2Client: Mockery::mock(Ec2Client::class));

    expect($service->resolveProvisionSecurityGroupId())->toBe('sg-custom');
});

test('resolve provision security group id reuses existing dply group', function () {
    config([
        'services.aws.security_group_id' => null,
        'services.aws.provision_security_group_name' => 'dply-provision',
    ]);

    $ec2 = Mockery::mock(Ec2Client::class);
    $ec2->shouldReceive('describeSecurityGroups')
        ->once()
        ->andReturn(new Result([
            'SecurityGroups' => [
                ['GroupName' => 'dply-provision', 'GroupId' => 'sg-existing'],
            ],
        ]));
    $ec2->shouldNotReceive('createSecurityGroup');

    $service = service(ec2Client: $ec2);

    expect($service->resolveProvisionSecurityGroupId())->toBe('sg-existing');
});

test('resolve provision security group id creates group with ssh ingress', function () {
    config([
        'services.aws.security_group_id' => null,
        'services.aws.provision_security_group_name' => 'dply-provision',
    ]);

    $ec2 = Mockery::mock(Ec2Client::class);
    $ec2->shouldReceive('describeSecurityGroups')
        ->once()
        ->andReturn(new Result(['SecurityGroups' => []]));
    $ec2->shouldReceive('createSecurityGroup')
        ->once()
        ->with(Mockery::on(fn (array $args): bool => $args['GroupName'] === 'dply-provision'))
        ->andReturn(new Result(['GroupId' => 'sg-new']));
    $ec2->shouldReceive('authorizeSecurityGroupIngress')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return ($args['GroupId'] ?? '') === 'sg-new'
                && ($args['IpPermissions'][0]['FromPort'] ?? null) === 22;
        }));

    $service = service(ec2Client: $ec2);

    expect($service->resolveProvisionSecurityGroupId())->toBe('sg-new');
});

test('run instances attaches public network interface with security group', function () {
    $ec2 = Mockery::mock(Ec2Client::class);
    $ec2->shouldReceive('runInstances')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return $args['ImageId'] === 'ami-test'
                && $args['InstanceType'] === 't3.micro'
                && $args['KeyName'] === 'dply-key'
                && ($args['NetworkInterfaces'][0]['AssociatePublicIpAddress'] ?? false) === true
                && ($args['NetworkInterfaces'][0]['Groups'] ?? []) === ['sg-test'];
        }))
        ->andReturn(new Result([
            'Instances' => [['InstanceId' => 'i-1234567890abcdef0']],
        ]));

    $service = service(ec2Client: $ec2);
    $id = $service->runInstances('ami-test', 't3.micro', 'dply-key', 'web-1', 'sg-test');

    expect($id)->toBe('i-1234567890abcdef0');
});

test('get public ip and state helpers read first instance', function () {
    $instances = [[
        'PublicIpAddress' => '203.0.113.10',
        'State' => ['Name' => 'running'],
    ]];

    expect(AwsEc2Service::getPublicIp($instances))->toBe('203.0.113.10');
    expect(AwsEc2Service::getState($instances))->toBe('running');
});

test('validate credentials calls describe regions', function () {
    $ec2 = Mockery::mock(Ec2Client::class);
    $ec2->shouldReceive('describeRegions')
        ->once()
        ->with(['AllRegions' => false]);

    service(ec2Client: $ec2)->validateCredentials();
});

function service(Ec2Client $ec2Client, ?SsmClient $ssmClient = null): AwsEc2Service
{
    $credential = new ProviderCredential([
        'provider' => 'aws',
        'credentials' => [
            'access_key_id' => 'AKIAFAKE',
            'secret_access_key' => 'fake-secret',
            'region' => 'us-east-1',
        ],
    ]);

    $service = (new AwsEc2Service($credential, 'us-east-1'))->withClient($ec2Client);

    if ($ssmClient !== null) {
        $service->withSsmClient($ssmClient);
    }

    return $service;
}
