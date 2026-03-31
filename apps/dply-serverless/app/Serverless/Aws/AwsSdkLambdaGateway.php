<?php

namespace App\Serverless\Aws;

use App\Contracts\AwsLambdaGateway;
use Aws\Exception\AwsException;
use Aws\Lambda\LambdaClient;
use RuntimeException;

/**
 * {@see LambdaClient} wrapper: {@see LambdaClient::getFunction} and {@see LambdaClient::updateFunctionCode} (zip bytes).
 */
final class AwsSdkLambdaGateway implements AwsLambdaGateway
{
    public function __construct(
        private LambdaClient $lambdaClient,
    ) {}

    public static function fromConfigRegion(string $region): self
    {
        return self::fromClientConfig([
            'version' => 'latest',
            'region' => $region,
        ]);
    }

    /**
     * @param  array<string, mixed>  $clientConfig
     */
    public static function fromClientConfig(array $clientConfig): self
    {
        return new self(new LambdaClient($clientConfig));
    }

    public function describeFunction(string $functionName): array
    {
        try {
            $result = $this->lambdaClient->getFunction(['FunctionName' => $functionName]);
        } catch (AwsException $e) {
            throw new RuntimeException('AWS Lambda: '.$e->getAwsErrorMessage(), 0, $e);
        }

        return $this->configurationToArnRevision($result['Configuration'] ?? []);
    }

    public function updateFunctionCodeWithZip(string $functionName, string $zipBinary): array
    {
        try {
            $result = $this->lambdaClient->updateFunctionCode([
                'FunctionName' => $functionName,
                'ZipFile' => $zipBinary,
                'Publish' => true,
            ]);
        } catch (AwsException $e) {
            throw new RuntimeException('AWS Lambda: '.$e->getAwsErrorMessage(), 0, $e);
        }

        return $this->updateResultToArnRevision([
            'FunctionArn' => $result['FunctionArn'] ?? null,
            'RevisionId' => $result['RevisionId'] ?? null,
        ]);
    }

    public function updateFunctionCodeFromS3(string $functionName, string $bucket, string $key, ?string $objectVersion = null): array
    {
        $params = [
            'FunctionName' => $functionName,
            'S3Bucket' => $bucket,
            'S3Key' => $key,
            'Publish' => true,
        ];
        if ($objectVersion !== null && $objectVersion !== '') {
            $params['S3ObjectVersion'] = $objectVersion;
        }

        try {
            $result = $this->lambdaClient->updateFunctionCode($params);
        } catch (AwsException $e) {
            throw new RuntimeException('AWS Lambda: '.$e->getAwsErrorMessage(), 0, $e);
        }

        return $this->updateResultToArnRevision([
            'FunctionArn' => $result['FunctionArn'] ?? null,
            'RevisionId' => $result['RevisionId'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{function_arn: string, revision_id: string}
     */
    private function configurationToArnRevision(array $configuration): array
    {
        $arn = isset($configuration['FunctionArn']) ? (string) $configuration['FunctionArn'] : '';
        if ($arn === '') {
            throw new RuntimeException('AWS Lambda: response missing FunctionArn.');
        }
        $revisionId = isset($configuration['RevisionId']) ? (string) $configuration['RevisionId'] : '';

        return [
            'function_arn' => $arn,
            'revision_id' => $revisionId !== '' ? $revisionId : 'unknown',
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{function_arn: string, revision_id: string}
     */
    private function updateResultToArnRevision(array $result): array
    {
        $arn = isset($result['FunctionArn']) ? (string) $result['FunctionArn'] : '';
        if ($arn === '') {
            throw new RuntimeException('AWS Lambda: UpdateFunctionCode response missing FunctionArn.');
        }
        $revisionId = isset($result['RevisionId']) ? (string) $result['RevisionId'] : '';

        return [
            'function_arn' => $arn,
            'revision_id' => $revisionId !== '' ? $revisionId : 'unknown',
        ];
    }
}
