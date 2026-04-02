<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Aws\Route53\Route53Client;

class Route53Service
{
    private Route53Client $client;

    public function __construct(ProviderCredential $credential)
    {
        $creds = $credential->credentials ?? [];
        $key = (string) ($creds['access_key_id'] ?? '');
        $secret = (string) ($creds['secret_access_key'] ?? '');

        if ($key === '' || $secret === '') {
            throw new \InvalidArgumentException('AWS access key ID and secret access key are required.');
        }

        $this->client = new Route53Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ]);
    }

    /**
     * @return array{id: string, name: string, value: string, type: string}
     */
    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        $zoneId = $this->findHostedZoneId($zone);
        $fqdn = rtrim($name, '.').'.'.rtrim($zone, '.').'.';

        $this->client->changeResourceRecordSets([
            'HostedZoneId' => $zoneId,
            'ChangeBatch' => [
                'Changes' => [[
                    'Action' => 'UPSERT',
                    'ResourceRecordSet' => [
                        'Name' => $fqdn,
                        'Type' => $type,
                        'TTL' => 60,
                        'ResourceRecords' => [
                            ['Value' => $value],
                        ],
                    ],
                ]],
            ],
        ]);

        return [
            'id' => $zoneId.':'.$fqdn.':'.$type,
            'name' => $name,
            'value' => $value,
            'type' => $type,
        ];
    }

    private function findHostedZoneId(string $zone): string
    {
        $result = $this->client->listHostedZonesByName([
            'DNSName' => rtrim($zone, '.').'.',
            'MaxItems' => '1',
        ]);

        $zoneData = $result['HostedZones'][0] ?? null;
        $zoneId = is_array($zoneData) ? (string) ($zoneData['Id'] ?? '') : '';

        if ($zoneId === '') {
            throw new \RuntimeException('AWS Route 53 hosted zone not found for '.$zone);
        }

        return str_replace('/hostedzone/', '', $zoneId);
    }
}
