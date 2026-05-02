<?php

namespace App\Contracts;

interface AwsLambdaGateway
{
    /**
     * @return array{function_arn: string, revision_id: string}
     */
    public function describeFunction(string $functionName): array;

    /**
     * @return array{function_arn: string, revision_id: string}
     */
    public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array;

    /**
     * @return array{function_arn: string, revision_id: string}
     */
    public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array;
}
