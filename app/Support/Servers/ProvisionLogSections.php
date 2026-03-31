<?php

namespace App\Support\Servers;

class ProvisionLogSections
{
    /**
     * @return list<array{key:string,status:string,detail:?string}>
     */
    public static function parseTaggedLines(string $output, string $prefix): array
    {
        $results = [];

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            if (! str_contains($line, $prefix)) {
                continue;
            }

            $payload = trim((string) strstr($line, $prefix));
            $payload = trim(str_replace($prefix, '', $payload));
            if ($payload === '') {
                continue;
            }

            $parts = array_map('trim', explode('::', $payload, 3));
            $results[] = [
                'key' => (string) ($parts[0] ?? 'unknown'),
                'status' => (string) ($parts[1] ?? 'info'),
                'detail' => ($parts[2] ?? null) !== null ? (string) $parts[2] : null,
            ];
        }

        return $results;
    }
}
