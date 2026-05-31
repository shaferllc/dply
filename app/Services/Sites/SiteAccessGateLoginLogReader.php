<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnectionFactory;
use Throwable;

final class SiteAccessGateLoginLogReader
{
    public function __construct(
        private readonly SshConnectionFactory $sshFactory,
    ) {}

    /**
     * @return list<array{at: string, label: string, credential_id: string, hostname: string, ip: string|null, user_agent: string|null}>
     */
    public function recent(Site $site, int $limit = 25): array
    {
        $site->loadMissing('server');
        $server = $site->server;

        if ($server === null || ! $server->hostCapabilities()->supportsSsh()) {
            return [];
        }

        $path = $site->accessGateStorageDirectoryOnHost().'/logins.jsonl';
        $limit = max(1, min($limit, 100));

        try {
            $ssh = $this->sshFactory->forServer($server);
            $raw = trim((string) $ssh->exec(
                sprintf('test -f %s && tail -n %d %s 2>/dev/null || true', escapeshellarg($path), $limit, escapeshellarg($path)),
                30,
            ));
        } catch (Throwable) {
            return [];
        }

        if ($raw === '') {
            return [];
        }

        $entries = [];

        foreach (array_reverse(explode("\n", $raw)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            $label = trim((string) ($decoded['label'] ?? ''));
            $credentialId = trim((string) ($decoded['credential_id'] ?? ''));
            $at = trim((string) ($decoded['at'] ?? ''));

            if ($label === '' || $at === '') {
                continue;
            }

            $entries[] = [
                'at' => $at,
                'label' => $label,
                'credential_id' => $credentialId,
                'hostname' => trim((string) ($decoded['hostname'] ?? '')),
                'ip' => isset($decoded['ip']) ? trim((string) $decoded['ip']) : null,
                'user_agent' => isset($decoded['user_agent']) ? trim((string) $decoded['user_agent']) : null,
            ];
        }

        return $entries;
    }
}
