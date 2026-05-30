<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\Servers\OpenRestyStaticConfigOptions;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;

/**
 * Probes OpenResty live state via `openresty -T`, `openresty -V`, and the
 * localhost stub_status endpoint wired into dply's nginx.conf template.
 */
class OpenRestyLiveStateProbe extends AbstractEngineLiveStateProbe
{
    use PrivilegedRemoteFileWrites;

    public function engineKey(): string
    {
        return 'openresty';
    }

    protected function runFreshProbe(Server $server): EngineLiveState
    {
        if ($standby = $this->inactiveEdgeProxyLiveState($server)) {
            return $standby;
        }

        $settings = OpenRestyStaticConfigOptions::operatorSettingsFromServer($server);
        $statusPort = max(1024, min(65535, (int) ($settings['status_port'] ?? 9149)));

        $ssh = new SshConnection($server);
        $script = $this->buildProbeScript($statusPort);
        $output = $ssh->exec($this->privilegedCommand($server, $script), 30);
        $exit = $ssh->lastExecExitCode();

        $errors = [];
        if ($exit !== null && $exit !== 0) {
            $errors[] = $this->extractProbeError((string) $output)
                ?? sprintf('OpenResty probe SSH exited %d', $exit);
        }

        $sections = $this->splitSections((string) $output);
        $configDump = $sections['config'] ?? '';
        $version = trim($sections['version'] ?? '');
        $stubStatus = trim($sections['status'] ?? '');

        return new EngineLiveState(
            engine: $this->engineKey(),
            capturedAt: CarbonImmutable::now(),
            isFresh: true,
            units: [
                'servers' => $this->buildServerUnits($configDump),
                'upstreams' => $this->buildUpstreamUnits($configDump),
                'runtime' => $this->buildRuntimeUnits($version, $stubStatus),
            ],
            engineSpecific: $errors === [] ? [] : ['errors' => $errors],
        );
    }

    private function buildProbeScript(int $statusPort): string
    {
        return <<<BASH
set +e
echo '###dply-section:version###'
openresty -V 2>&1 | head -n 1
echo '###dply-section:end###'
echo '###dply-section:config###'
openresty -T 2>/dev/null
echo '###dply-section:end###'
echo '###dply-section:status###'
curl -fsS --max-time 3 http://127.0.0.1:{$statusPort}/nginx_status 2>/dev/null
echo
echo '###dply-section:end###'
BASH;
    }

    private function extractProbeError(string $output): ?string
    {
        foreach (preg_split('/\r\n|\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[dply]')) {
                return trim(substr($line, 6));
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function splitSections(string $output): array
    {
        $heads = ['version', 'config', 'status'];
        $end = '###dply-section:end###';
        $out = [];
        foreach ($heads as $name) {
            $head = '###dply-section:'.$name.'###';
            $start = strpos($output, $head);
            if ($start === false) {
                continue;
            }
            $start += strlen($head);
            $stop = strpos($output, $end, $start);
            $out[$name] = $stop === false ? substr($output, $start) : substr($output, $start, $stop - $start);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildServerUnits(string $configDump): array
    {
        $blocks = $this->extractTopLevelBlocks($configDump, 'server');
        $rows = [];
        foreach ($blocks as $block) {
            $serverNames = $this->extractDirectiveTokens($block, 'server_name');
            $listens = $this->extractDirectiveTokens($block, 'listen');
            $proxyPass = $this->extractProxyPass($block);
            if ($serverNames === ['_'] && str_contains($block, 'default_server')) {
                $rows[] = [
                    'name' => 'dply_unmatched',
                    'server_names' => '_',
                    'listen' => implode(', ', $listens),
                    'upstream' => '503 catch-all',
                ];

                continue;
            }
            if (str_contains($block, 'stub_status')) {
                continue;
            }
            $rows[] = [
                'name' => $serverNames[0] ?? '(unnamed)',
                'server_names' => implode(' ', $serverNames),
                'listen' => implode(', ', $listens),
                'upstream' => $proxyPass,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildUpstreamUnits(string $configDump): array
    {
        $blocks = $this->extractTopLevelBlocks($configDump, 'upstream');
        $rows = [];
        foreach ($blocks as $block) {
            if (! preg_match('/^\s*upstream\s+([^\s{]+)/m', $block, $m)) {
                continue;
            }
            $servers = [];
            foreach ($this->extractDirectiveTokens($block, 'server') as $token) {
                $servers[] = rtrim($token, ';');
            }
            $rows[] = [
                'name' => trim($m[1]),
                'servers' => $servers,
                'server_count' => count($servers),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRuntimeUnits(string $version, string $stubStatus): array
    {
        $active = $reading = $writing = $waiting = null;
        if (preg_match('/Active connections:\s*(\d+)/', $stubStatus, $m)) {
            $active = (int) $m[1];
        }
        if (preg_match('/Reading:\s*(\d+)\s+Writing:\s*(\d+)\s+Waiting:\s*(\d+)/', $stubStatus, $m)) {
            $reading = (int) $m[1];
            $writing = (int) $m[2];
            $waiting = (int) $m[3];
        }

        return [[
            'version' => $version !== '' ? $version : '?',
            'active_connections' => $active,
            'reading' => $reading,
            'writing' => $writing,
            'waiting' => $waiting,
            'stub_status' => $stubStatus !== '' ? $stubStatus : null,
        ]];
    }

    /**
     * @return list<string>
     */
    private function extractTopLevelBlocks(string $config, string $keyword): array
    {
        $blocks = [];
        $pattern = '/\b'.preg_quote($keyword, '/').'\s*\{/';
        $offset = 0;
        while (preg_match($pattern, $config, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $depth = 0;
            $len = strlen($config);
            for ($i = $start; $i < $len; $i++) {
                $ch = $config[$i];
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $blocks[] = substr($config, $start, $i - $start + 1);
                        $offset = $i + 1;
                        break;
                    }
                }
            }
            if ($depth !== 0) {
                break;
            }
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function extractDirectiveTokens(string $block, string $directive): array
    {
        $tokens = [];
        foreach (preg_split('/\r\n|\n/', $block) ?: [] as $line) {
            $line = trim($line);
            if (! str_starts_with($line, $directive.' ')) {
                continue;
            }
            $value = trim(substr($line, strlen($directive)));
            $value = rtrim($value, ';');
            if ($value !== '') {
                $tokens[] = $value;
            }
        }

        return $tokens;
    }

    private function extractProxyPass(string $block): string
    {
        foreach (preg_split('/\r\n|\n/', $block) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'proxy_pass ')) {
                return trim(rtrim(substr($line, strlen('proxy_pass ')), ';'));
            }
        }

        return '';
    }
}
