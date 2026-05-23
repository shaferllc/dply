<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Edge\EdgeCloudflareClient;
use App\Support\Edge\EdgeLocalDevDiagnostics;
use App\Support\Edge\EdgePlatformCredentials;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Validate dply Edge production platform credentials and optional live probes.
 *
 *   dply:edge:doctor [--probe] [--json]
 */
class EdgeDoctorCommand extends Command
{
    private const PROBE_KV_KEY = '__dply_edge_doctor__';

    private const PROBE_R2_KEY = 'edge/__doctor__/probe.txt';

    protected $signature = 'dply:edge:doctor
                            {--probe : Write/read/delete probe objects in R2 and KV}
                            {--json : Output JSON}';

    protected $description = 'Validate Edge platform credentials (R2, KV, Cloudflare API).';

    public function handle(): int
    {
        $report = $this->compileReport();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHuman($report);

        return ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function compileReport(): array
    {
        $summary = EdgePlatformCredentials::summary();
        $missing = EdgePlatformCredentials::missing();
        $checks = [];

        if (FakeEdgeProvision::enabled()) {
            $checks = EdgeLocalDevDiagnostics::checks();
            $failed = array_values(array_filter($checks, fn (array $check): bool => ($check['ok'] ?? false) === false));

            return [
                'ok' => true,
                'mode' => 'fake',
                'message' => $failed === []
                    ? 'DPLY_FAKE_EDGE is active — local hostname checks passed.'
                    : 'DPLY_FAKE_EDGE is active — local DNS may need setup (see checks below).',
                'summary' => $summary,
                'missing' => [],
                'checks' => $checks,
            ];
        }

        if ($missing !== []) {
            return [
                'ok' => false,
                'mode' => 'production',
                'message' => 'Missing required Edge platform credentials.',
                'summary' => $summary,
                'missing' => $missing,
                'checks' => [],
            ];
        }

        $checks[] = $this->checkCloudflareToken();

        if ($this->option('probe')) {
            $checks[] = $this->checkKvProbe();
            $checks[] = $this->checkR2Probe();
        }

        $failed = array_values(array_filter($checks, fn (array $check): bool => ($check['ok'] ?? false) === false));

        return [
            'ok' => $failed === [],
            'mode' => 'production',
            'message' => $failed === [] ? 'Edge platform credentials look good.' : 'One or more checks failed.',
            'summary' => $summary,
            'missing' => [],
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCloudflareToken(): array
    {
        try {
            $result = EdgeCloudflareClient::fromConfig()->verifyToken();
            $status = is_string($result['status'] ?? null) ? $result['status'] : 'unknown';

            return [
                'name' => 'cloudflare_api_token',
                'ok' => $status === 'active',
                'detail' => 'Token status: '.$status,
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'cloudflare_api_token',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkKvProbe(): array
    {
        $accountId = (string) config('edge.cloudflare.account_id');
        $namespaceId = (string) config('edge.cloudflare.kv_namespace_id');
        $token = (string) config('edge.cloudflare.api_token');
        $url = 'https://api.cloudflare.com/client/v4/accounts/'.$accountId
            .'/storage/kv/namespaces/'.$namespaceId.'/values/'.rawurlencode(self::PROBE_KV_KEY);

        try {
            Http::withToken($token)
                ->withBody('{"probe":true}', 'application/json')
                ->put($url)
                ->throw();

            Http::withToken($token)->get($url)->throw();

            Http::withToken($token)->delete($url)->throw();

            return [
                'name' => 'kv_write_read_delete',
                'ok' => true,
                'detail' => 'KV namespace accepts host-map writes.',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'kv_write_read_delete',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkR2Probe(): array
    {
        try {
            $disk = Storage::disk((string) config('edge.disk.name', 'edge_r2'));
            $disk->put(self::PROBE_R2_KEY, 'dply-edge-doctor', ['visibility' => 'private']);
            $exists = $disk->exists(self::PROBE_R2_KEY);
            $disk->delete(self::PROBE_R2_KEY);

            return [
                'name' => 'r2_write_read_delete',
                'ok' => $exists,
                'detail' => $exists ? 'R2 bucket accepts artifact uploads.' : 'Probe object missing after upload.',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'r2_write_read_delete',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->info((string) ($report['message'] ?? 'Edge doctor'));
        $this->newLine();

        if (($report['mode'] ?? '') === 'fake') {
            $this->warn('Fake edge mode is enabled. Set DPLY_FAKE_EDGE=false in production.');
            $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
            if ($checks !== []) {
                $this->newLine();
                $this->table(
                    ['Check', 'OK', 'Detail'],
                    array_map(fn (array $check): array => [
                        (string) ($check['name'] ?? ''),
                        ($check['ok'] ?? false) ? 'yes' : 'no',
                        (string) ($check['detail'] ?? ''),
                    ], $checks),
                );
            }
            $this->newLine();
            $this->line('See: docs/edge-local-development.md');

            return;
        }

        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $this->table(['Setting', 'Value'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values()->all());

        $missing = is_array($report['missing'] ?? null) ? $report['missing'] : [];
        if ($missing !== []) {
            $this->newLine();
            $this->error('Missing credentials:');
            foreach ($missing as $item) {
                $this->line('  - '.$item);
            }
            $this->newLine();
            $this->line('Run: php artisan dply:edge:infra:bootstrap --help');
            $this->line('See: docs/edge-production-setup.md');

            return;
        }

        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        if ($checks !== []) {
            $this->newLine();
            $this->table(
                ['Check', 'OK', 'Detail'],
                array_map(fn (array $check): array => [
                    (string) ($check['name'] ?? ''),
                    ($check['ok'] ?? false) ? 'yes' : 'no',
                    (string) ($check['detail'] ?? ''),
                ], $checks),
            );
        }

        if (! $this->option('probe') && ($report['ok'] ?? false)) {
            $this->newLine();
            $this->line('Tip: re-run with --probe to test live R2 + KV writes.');
        }
    }
}
