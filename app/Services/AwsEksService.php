<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProviderCredential;
use Aws\Eks\EksClient;

/**
 * Thin wrapper around the EKS API. Currently only used to list clusters for
 * the server-create wizard's K8s host_kind path; mirrors the shape of
 * {@see AwsEc2Service} so future EKS calls (describe-cluster, kubeconfig
 * resolution, etc.) drop in here without spinning up a parallel client.
 */
class AwsEksService
{
    protected EksClient $client;

    protected string $region;

    public function __construct(ProviderCredential $credential, ?string $region = null)
    {
        $creds = $credential->credentials ?? [];
        $key = (string) ($creds['access_key_id'] ?? '');
        $secret = (string) ($creds['secret_access_key'] ?? '');
        if ($key === '' || $secret === '') {
            throw new \InvalidArgumentException('AWS access key ID and secret access key are required.');
        }
        $this->region = $region ?? (string) ($creds['region'] ?? config('services.aws.default_region', 'us-east-1'));
        $this->client = new EksClient([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ]);
    }

    /**
     * List EKS cluster names in the credential's region. Paginated under the
     * hood — EKS returns up to 100 names per page; loop until nextToken empty.
     *
     * @return list<string>
     */
    public function listClusterNames(): array
    {
        $names = [];
        $token = null;

        do {
            $params = [];
            if ($token !== null) {
                $params['nextToken'] = $token;
            }
            $result = $this->client->listClusters($params);
            foreach ((array) ($result['clusters'] ?? []) as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
            $token = is_string($result['nextToken'] ?? null) && $result['nextToken'] !== '' ? $result['nextToken'] : null;
        } while ($token !== null);

        return $names;
    }
}
