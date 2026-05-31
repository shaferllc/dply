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
        $fqdn = $this->fqdnForRecord($name, $zone);

        $this->client->changeResourceRecordSets([
            'HostedZoneId' => $zoneId,
            'ChangeBatch' => [
                'Changes' => [[
                    'Action' => 'UPSERT',
                    'ResourceRecordSet' => [
                        'Name' => $fqdn,
                        'Type' => strtoupper($type),
                        'TTL' => 60,
                        'ResourceRecords' => [
                            ['Value' => $value],
                        ],
                    ],
                ]],
            ],
        ]);

        return [
            'id' => implode('|', [$zoneId, $fqdn, strtoupper($type), $value]),
            'name' => self::normalizeRecordName($name, $zone),
            'value' => $value,
            'type' => strtoupper($type),
        ];
    }

    public function hostedZoneExists(string $zone): bool
    {
        try {
            $this->findHostedZoneId($zone);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteRecord(string $recordId): void
    {
        if ($recordId === '' || ! str_contains($recordId, '|')) {
            return;
        }

        $parts = explode('|', $recordId, 4);
        if (count($parts) !== 4) {
            return;
        }

        [$zoneId, $fqdn, $type, $value] = $parts;
        if ($zoneId === '' || $fqdn === '' || $type === '' || $value === '') {
            return;
        }

        $this->client->changeResourceRecordSets([
            'HostedZoneId' => $zoneId,
            'ChangeBatch' => [
                'Changes' => [[
                    'Action' => 'DELETE',
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
    }

    public static function normalizeRecordName(string $recordName, string $zoneName): string
    {
        $recordName = strtolower(trim($recordName));
        $zoneName = strtolower(trim($zoneName));

        if ($recordName === '' || $recordName === '@' || ($zoneName !== '' && $recordName === $zoneName)) {
            return '';
        }

        if ($zoneName !== '' && str_ends_with($recordName, '.'.$zoneName)) {
            $recordName = substr($recordName, 0, -1 * (strlen($zoneName) + 1));
        }

        return $recordName;
    }

    private function fqdnForRecord(string $name, string $zone): string
    {
        $relative = self::normalizeRecordName($name, $zone);
        $zone = rtrim(strtolower(trim($zone)), '.');

        if ($relative === '') {
            return $zone.'.';
        }

        return $relative.'.'.$zone.'.';
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
