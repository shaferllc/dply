<?php

namespace App\Contracts;

use App\Serverless\Aws\AwsLambdaSdkProvisioner;

/**
 * Lambda SDK operations used by {@see AwsLambdaSdkProvisioner}.
 */
interface AwsLambdaGateway
{
    /**
     * @return array{function_arn: string, revision_id: string}
     *
     * @throws \RuntimeException When AWS returns an error or the response is unusable.
     */
    public function describeFunction(string $functionName): array;

    /**
     * @return array{function_arn: string, revision_id: string}
     *
     * @throws \RuntimeException When AWS returns an error or the response is unusable.
     */
    public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array;

    /**
     * @return array{function_arn: string, revision_id: string}
     *
     * @throws \RuntimeException When AWS returns an error or the response is unusable.
     */
    public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array;
}
