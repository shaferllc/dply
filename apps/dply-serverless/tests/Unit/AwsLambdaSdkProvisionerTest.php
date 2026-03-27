<?php

namespace Tests\Unit;

use App\Contracts\AwsLambdaGateway;
use App\Serverless\Aws\AwsLambdaSdkProvisioner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AwsLambdaSdkProvisionerTest extends TestCase
{
    public function test_describe_only_when_zip_upload_disabled(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public bool $updated = false;

            public function describeFunction(string $functionName): array
            {
                return [
                    'function_arn' => 'arn:aws:lambda:us-east-1:111122223333:function:'.$functionName,
                    'revision_id' => 'rev-d',
                ];
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                $this->updated = true;

                return ['function_arn' => 'x', 'revision_id' => 'y'];
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                throw new RuntimeException('S3 path unexpected in this test');
            }
        };

        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', false, sys_get_temp_dir(), 1024 * 1024, []);
        $out = $provisioner->deployFunction('my-fn', 'provided.al2023', '/tmp/any.zip', ['foo' => 1]);

        $this->assertFalse($gateway->updated);
        $this->assertSame('aws', $out['provider']);
        $this->assertSame('arn:aws:lambda:us-east-1:111122223333:function:my-fn', $out['function_arn']);
        $this->assertSame('rev-d', $out['revision_id']);
        $this->assertSame(['foo'], $out['config_keys']);
    }

    public function test_config_keys_redact_credentials_array(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public function describeFunction(string $functionName): array
            {
                return ['function_arn' => 'arn:aws:lambda:us-east-1:111122223333:function:'.$functionName, 'revision_id' => 'r1'];
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                throw new RuntimeException('unexpected');
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $versionId = null): array
            {
                throw new RuntimeException('unexpected');
            }
        };

        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', false, sys_get_temp_dir(), 1024 * 1024, []);
        $out = $provisioner->deployFunction('fn', 'provided.al2023', '/tmp/x.zip', [
            'foo' => 1,
            'credentials' => ['x' => 'y'],
        ]);

        $this->assertSame(['credentials_present', 'foo'], $out['config_keys']);
    }

    public function test_upload_zip_when_prefix_matches_file(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dplyzip');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'PK'.str_repeat("\0", 20));

        $gateway = new class implements AwsLambdaGateway
        {
            public ?string $receivedZip = null;

            public function describeFunction(string $functionName): array
            {
                throw new RuntimeException('describe should not run when zip path used');
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                $this->receivedZip = $zipBinary;

                return [
                    'function_arn' => 'arn:aws:lambda:us-east-1:1:function:uploaded',
                    'revision_id' => 'rev-up',
                ];
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                throw new RuntimeException('S3 path unexpected in this test');
            }
        };

        $prefix = sys_get_temp_dir();
        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', true, $prefix, 1024 * 1024, []);
        $out = $provisioner->deployFunction('fn', 'rt', $tmp, []);

        $this->assertSame('PK'.str_repeat("\0", 20), $gateway->receivedZip);
        $this->assertSame('rev-up', $out['revision_id']);
        @unlink($tmp);
    }

    public function test_falls_back_to_describe_when_prefix_not_configured(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public function describeFunction(string $functionName): array
            {
                return ['function_arn' => 'arn:a', 'revision_id' => 'r'];
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                throw new RuntimeException('no upload');
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                throw new RuntimeException('S3 path unexpected in this test');
            }
        };

        $tmp = tempnam(sys_get_temp_dir(), 'dply');
        file_put_contents($tmp, 'x');
        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', true, null, 1024, []);
        $out = $provisioner->deployFunction('fn', 'rt', $tmp, []);
        $this->assertSame('r', $out['revision_id']);
        @unlink($tmp);
    }

    public function test_s3_artifact_calls_gateway_when_bucket_allowed(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public ?array $s3 = null;

            public function describeFunction(string $functionName): array
            {
                throw new RuntimeException('describe should not run when S3 artifact used');
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                throw new RuntimeException('zip should not run when S3 artifact used');
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                $this->s3 = compact('functionName', 'bucket', 'key', 'objectVersion');

                return [
                    'function_arn' => 'arn:aws:lambda:us-east-1:1:function:s3',
                    'revision_id' => 'rev-s3',
                ];
            }
        };

        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', true, sys_get_temp_dir(), 1024 * 1024, ['my-artifacts']);
        $out = $provisioner->deployFunction('my-fn', 'provided.al2023', 's3://my-artifacts/releases/app.zip?versionId=vid-1', []);

        $this->assertSame([
            'functionName' => 'my-fn',
            'bucket' => 'my-artifacts',
            'key' => 'releases/app.zip',
            'objectVersion' => 'vid-1',
        ], $gateway->s3);
        $this->assertSame('rev-s3', $out['revision_id']);
    }

    public function test_s3_artifact_without_allow_list_throws(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public function describeFunction(string $functionName): array
            {
                return ['function_arn' => 'arn:x', 'revision_id' => 'r'];
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                throw new RuntimeException('zip');
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                throw new RuntimeException('s3');
            }
        };

        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', false, null, 1024, []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SERVERLESS_AWS_S3_ALLOW_BUCKETS');

        $provisioner->deployFunction('fn', 'rt', 's3://bbb/k.zip', []);
    }

    public function test_s3_artifact_rejects_bucket_not_in_allow_list(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public function describeFunction(string $functionName): array
            {
                return ['function_arn' => 'arn:x', 'revision_id' => 'r'];
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                throw new RuntimeException('zip');
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                throw new RuntimeException('s3');
            }
        };

        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', false, null, 1024, ['allowed-only']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed for this deploy');

        $provisioner->deployFunction('fn', 'rt', 's3://other-bucket/k.zip', []);
    }

    public function test_s3_artifact_rejects_path_traversal_in_key(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public function describeFunction(string $functionName): array
            {
                return ['function_arn' => 'arn:x', 'revision_id' => 'r'];
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                throw new RuntimeException('zip');
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                throw new RuntimeException('s3');
            }
        };

        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', false, null, 1024, ['my-bucket']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path segments');

        $provisioner->deployFunction('fn', 'rt', 's3://my-bucket/a/../b.zip', []);
    }

    public function test_s3_artifact_allows_bucket_in_project_subset_of_global(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public ?array $s3 = null;

            public function describeFunction(string $functionName): array
            {
                throw new RuntimeException('unexpected');
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                throw new RuntimeException('unexpected');
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                $this->s3 = compact('functionName', 'bucket', 'key', 'objectVersion');

                return ['function_arn' => 'arn:aws:lambda:us-east-1:1:function:x', 'revision_id' => 'r1'];
            }
        };

        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', false, null, 1024, ['bucket-a', 'bucket-b']);
        $provisioner->deployFunction('fn', 'provided.al2023', 's3://bucket-b/app.zip', [
            'project' => ['settings' => ['aws_s3_allow_buckets' => ['bucket-b']]],
        ]);

        $this->assertSame('bucket-b', $gateway->s3['bucket'] ?? null);
    }

    public function test_s3_artifact_rejects_when_project_bucket_list_disjoint_from_global(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public function describeFunction(string $functionName): array
            {
                return ['function_arn' => 'arn:x', 'revision_id' => 'r'];
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                throw new RuntimeException('zip');
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                throw new RuntimeException('s3');
            }
        };

        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', false, null, 1024, ['global-only']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed for this project');

        $provisioner->deployFunction('fn', 'rt', 's3://global-only/k.zip', [
            'project' => ['settings' => ['aws_s3_allow_buckets' => ['tenant-other']]],
        ]);
    }

    public function test_s3_artifact_rejects_bucket_outside_project_subset(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public function describeFunction(string $functionName): array
            {
                return ['function_arn' => 'arn:x', 'revision_id' => 'r'];
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                throw new RuntimeException('zip');
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                throw new RuntimeException('s3');
            }
        };

        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', false, null, 1024, ['bucket-a', 'bucket-b']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed for this deploy');

        $provisioner->deployFunction('fn', 'rt', 's3://bucket-b/nope.zip', [
            'project' => ['settings' => ['aws_s3_allow_buckets' => ['bucket-a']]],
        ]);
    }

    public function test_s3_artifact_explicit_empty_project_allow_list_blocks(): void
    {
        $gateway = new class implements AwsLambdaGateway
        {
            public function describeFunction(string $functionName): array
            {
                return ['function_arn' => 'arn:x', 'revision_id' => 'r'];
            }

            public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
            {
                throw new RuntimeException('zip');
            }

            public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
            {
                throw new RuntimeException('s3');
            }
        };

        $provisioner = new AwsLambdaSdkProvisioner($gateway, 'us-east-1', false, null, 1024, ['allowed-bucket']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed for this project');

        $provisioner->deployFunction('fn', 'rt', 's3://allowed-bucket/k.zip', [
            'project' => ['settings' => ['aws_s3_allow_buckets' => []]],
        ]);
    }
}
