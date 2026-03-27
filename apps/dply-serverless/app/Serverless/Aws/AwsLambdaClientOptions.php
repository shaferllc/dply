<?php

namespace App\Serverless\Aws;

/**
 * Builds Lambda client options from global default region + optional {@see ServerlessDeployContext} providerConfig.
 */
final class AwsLambdaClientOptions
{
    /**
     * @param  array<string, mixed>  $providerConfig
     * @return array{client_config: array<string, mixed>, equals_default: bool}
     */
    public static function resolve(string $defaultRegion, array $providerConfig): array
    {
        $defaultRegion = trim($defaultRegion);
        if ($defaultRegion === '') {
            $defaultRegion = 'us-east-1';
        }

        $settings = [];
        if (isset($providerConfig['project']['settings']) && is_array($providerConfig['project']['settings'])) {
            $settings = $providerConfig['project']['settings'];
        }

        $creds = [];
        if (isset($providerConfig['credentials']) && is_array($providerConfig['credentials'])) {
            $creds = $providerConfig['credentials'];
        }

        $region = trim((string) ($settings['aws_region'] ?? ''));
        if ($region === '') {
            $region = $defaultRegion;
        }

        $key = trim((string) ($creds['access_key_id'] ?? $creds['aws_access_key_id'] ?? ''));
        $secret = trim((string) ($creds['secret_access_key'] ?? $creds['aws_secret_access_key'] ?? ''));
        $token = trim((string) ($creds['session_token'] ?? $creds['aws_session_token'] ?? ''));

        $static = null;
        if ($key !== '' && $secret !== '') {
            $static = ['key' => $key, 'secret' => $secret];
            if ($token !== '') {
                $static['token'] = $token;
            }
        }

        $equalsDefault = ($region === $defaultRegion) && $static === null;

        $clientConfig = [
            'version' => 'latest',
            'region' => $region,
        ];
        if ($static !== null) {
            $clientConfig['credentials'] = $static;
        }

        return [
            'client_config' => $clientConfig,
            'equals_default' => $equalsDefault,
        ];
    }
}
