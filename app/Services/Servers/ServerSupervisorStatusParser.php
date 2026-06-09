<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;

/**
 * Parse supervisorctl status output for Dply-managed programs.
 */
final class ServerSupervisorStatusParser
{
    /**
     * @return list<array{
     *     program_id: string,
     *     slug: string,
     *     program_type: string,
     *     site_id: ?string,
     *     site_name: ?string,
     *     state: string,
     *     uptime: ?string,
     *     raw: string,
     *     healthy: bool,
     * }>
     */
    public function parseForServer(Server $server, string $statusOutput): array
    {
        $programs = $server->supervisorPrograms()
            ->with('site:id,name')
            ->where('is_active', true)
            ->get(['id', 'site_id', 'slug', 'program_type']);

        $prefixMap = [];
        foreach ($programs as $program) {
            $prefixMap['dply-sv-'.$program->id] = $program;
        }

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $statusOutput) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            foreach ($prefixMap as $prefix => $program) {
                if (! str_starts_with($line, $prefix)) {
                    continue;
                }

                $state = $this->extractState($line);
                $uptime = $this->extractUptime($line);
                $healthy = $state === 'RUNNING';

                $rows[] = [
                    'program_id' => (string) $program->id,
                    'slug' => (string) $program->slug,
                    'program_type' => (string) $program->program_type,
                    'site_id' => $program->site_id !== null ? (string) $program->site_id : null,
                    'site_name' => $program->site !== null ? (string) $program->site->name : null,
                    'state' => $state,
                    'uptime' => $uptime,
                    'raw' => $line,
                    'healthy' => $healthy,
                ];
                break;
            }
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['healthy'] !== $b['healthy']) {
                return $a['healthy'] <=> $b['healthy'];
            }

            return strcmp($a['slug'], $b['slug']);
        });

        return $rows;
    }

    private function extractState(string $line): string
    {
        if (preg_match('/\b(RUNNING|STOPPED|FATAL|BACKOFF|EXITED|STARTING|UNKNOWN)\b/i', $line, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'UNKNOWN';
    }

    private function extractUptime(string $line): ?string
    {
        if (preg_match('/\buptime\s+(.+)$/i', $line, $matches)) {
            $value = trim($matches[1]);
            if (preg_match('/^pid\s+\d+,\s*(.+)$/i', $value, $nested)) {
                return trim($nested[1]);
            }

            return $value !== '' ? $value : null;
        }

        return null;
    }
}
