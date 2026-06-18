<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Console;

use App\Modules\Realtime\Services\RealtimeBackendFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-shot readiness check for the managed Realtime resource — validates the
 * feature flag, Cloudflare credentials/KV, the live Worker, and the Stripe
 * price, so a cutover is a single green/red readout.
 *
 *   dply:realtime:doctor [--probe] [--json]
 */
class RealtimeDoctorCommand extends Command
{
    private const PROBE_KV_KEY = '__dply_realtime_doctor__';

    protected $signature = 'dply:realtime:doctor
                            {--probe : Write/read/delete a probe key in the realtime KV namespace}
                            {--json : Output JSON}';

    protected $description = 'Validate the managed Realtime resource (feature flag, Cloudflare KV, Worker, Stripe).';

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
        $featureEnabled = (bool) config('features.surface.realtime');
        $host = (string) config('realtime.host');

        $tiers = (array) config('realtime.tiers', []);
        $tierPricesMissing = $this->missingTierPrices();

        $summary = [
            'feature_enabled' => $featureEnabled ? 'yes' : 'no',
            'fake_mode' => RealtimeBackendFactory::fakeEnabled() ? 'yes' : 'no',
            'host' => $host !== '' ? $host : '(unset)',
            'cf_account_id' => $this->present(config('realtime.cloudflare.account_id')),
            'cf_api_token' => $this->present(config('realtime.cloudflare.api_token')),
            'cf_kv_namespace_id' => $this->present(config('realtime.cloudflare.kv_namespace_id')),
            'stripe_tier_prices' => $tierPricesMissing === []
                ? 'set ('.implode(', ', array_keys($tiers)).')'
                : count($tierPricesMissing).' missing',
        ];

        // Checks that apply regardless of mode.
        $alwaysChecks = [
            $this->checkFeatureFlag($featureEnabled),
            $this->checkStripePrice($tierPricesMissing, array_keys($tiers)),
            $this->checkWorkerHealth($host),
        ];

        if (RealtimeBackendFactory::fakeEnabled()) {
            return [
                'ok' => true,
                'mode' => 'fake',
                'message' => 'DPLY_FAKE_REALTIME is active — apps provision to the local cache. Set it false + configure Cloudflare for live.',
                'summary' => $summary,
                'missing' => [],
                'checks' => $alwaysChecks,
            ];
        }

        $missing = $this->missingCredentials();
        if ($missing !== []) {
            return [
                'ok' => false,
                'mode' => 'production',
                'message' => 'Missing required Realtime Cloudflare credentials.',
                'summary' => $summary,
                'missing' => $missing,
                'checks' => $alwaysChecks,
            ];
        }

        $checks = array_merge($alwaysChecks, [$this->checkCloudflareToken()]);

        if ($this->option('probe')) {
            $checks[] = $this->checkKvProbe();
        }

        $failed = array_values(array_filter($checks, fn (array $c): bool => ($c['ok'] ?? false) === false));

        return [
            'ok' => $failed === [],
            'mode' => 'production',
            'message' => $failed === [] ? 'Realtime resource looks ready.' : 'One or more checks failed.',
            'summary' => $summary,
            'missing' => [],
            'checks' => $checks,
        ];
    }

    /**
     * @return list<string>
     */
    private function missingCredentials(): array
    {
        $missing = [];
        if ((string) config('realtime.cloudflare.account_id') === '') {
            $missing[] = 'DPLY_REALTIME_CF_ACCOUNT_ID (or DPLY_EDGE_CF_ACCOUNT_ID)';
        }
        if ((string) config('realtime.cloudflare.api_token') === '') {
            $missing[] = 'DPLY_REALTIME_CF_API_TOKEN (or DPLY_EDGE_CF_API_TOKEN) — needs Workers KV Storage: Edit';
        }
        if ((string) config('realtime.cloudflare.kv_namespace_id') === '') {
            $missing[] = 'DPLY_REALTIME_CF_KV_NAMESPACE_ID (wrangler kv namespace create APPS)';
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkFeatureFlag(bool $enabled): array
    {
        return [
            'name' => 'feature_flag',
            'ok' => $enabled,
            'detail' => $enabled
                ? 'surface.realtime is on. (After flipping the env, run php artisan pennant:purge.)'
                : 'Set FEATURE_SURFACE_REALTIME=true, then php artisan config:clear && php artisan pennant:purge.',
        ];
    }

    /**
     * Per-tier Stripe price env vars that are not set. Apps are billed by their
     * connection tier, so every configured tier needs a monthly + yearly price.
     *
     * @return list<string>
     */
    private function missingTierPrices(): array
    {
        $monthly = (array) config('subscription.standard.stripe.realtime_tiers', []);
        $yearly = (array) config('subscription.standard.stripe.realtime_tiers_yearly', []);

        $missing = [];
        foreach (array_keys((array) config('realtime.tiers', [])) as $slug) {
            $slug = (string) $slug;
            if ((string) ($monthly[$slug] ?? '') === '') {
                $missing[] = 'STRIPE_PRICE_STANDARD_REALTIME_'.strtoupper($slug);
            }
            if ((string) ($yearly[$slug] ?? '') === '') {
                $missing[] = 'STRIPE_PRICE_STANDARD_REALTIME_'.strtoupper($slug).'_YEARLY';
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $missing
     * @param  list<int|string>  $tierSlugs
     * @return array<string, mixed>
     */
    private function checkStripePrice(array $missing, array $tierSlugs): array
    {
        return [
            'name' => 'stripe_tier_prices',
            'ok' => $missing === [],
            'detail' => $missing === []
                ? 'All connection-tier prices set ('.implode(', ', array_map('strval', $tierSlugs)).').'
                : 'Active apps on these tiers will not bill — missing: '.implode(', ', $missing).'. Run php artisan dply:billing:provision-stripe and paste the printed STRIPE_PRICE_STANDARD_REALTIME_* lines.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkWorkerHealth(string $host): array
    {
        if ($host === '') {
            return ['name' => 'worker_health', 'ok' => false, 'detail' => 'DPLY_REALTIME_HOST is unset.'];
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get('https://'.$host.'/health');
            $service = (string) $response->json('service');
            $ok = $response->successful() && $service === 'dply-realtime';

            return [
                'name' => 'worker_health',
                'ok' => $ok,
                'detail' => $ok
                    ? 'Worker reachable at https://'.$host.'/health.'
                    : 'Unexpected response (HTTP '.$response->status().'). Deploy packages/realtime-worker and route it on '.$host.'.',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'worker_health',
                'ok' => false,
                'detail' => 'Could not reach https://'.$host.'/health — '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCloudflareToken(): array
    {
        try {
            $response = Http::withToken((string) config('realtime.cloudflare.api_token'))
                ->acceptJson()
                ->get('https://api.cloudflare.com/client/v4/user/tokens/verify');

            $status = (string) $response->json('result.status');

            return [
                'name' => 'cloudflare_api_token',
                'ok' => $status === 'active',
                'detail' => $status === 'active' ? 'Token status: active.' : 'Token status: '.($status ?: 'invalid').'.',
            ];
        } catch (\Throwable $e) {
            return ['name' => 'cloudflare_api_token', 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkKvProbe(): array
    {
        $accountId = (string) config('realtime.cloudflare.account_id');
        $namespaceId = (string) config('realtime.cloudflare.kv_namespace_id');
        $token = (string) config('realtime.cloudflare.api_token');
        $url = 'https://api.cloudflare.com/client/v4/accounts/'.$accountId
            .'/storage/kv/namespaces/'.$namespaceId.'/values/'.rawurlencode(self::PROBE_KV_KEY);

        try {
            Http::withToken($token)->withBody('{"probe":true}', 'text/plain')->put($url)->throw();
            Http::withToken($token)->get($url)->throw();
            Http::withToken($token)->delete($url)->throw();

            return [
                'name' => 'kv_write_read_delete',
                'ok' => true,
                'detail' => 'Realtime KV namespace accepts credential writes (token has KV Edit).',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'kv_write_read_delete',
                'ok' => false,
                'detail' => 'KV probe failed — check the namespace id + token Workers KV Storage: Edit scope. '.$e->getMessage(),
            ];
        }
    }

    private function present(mixed $value): string
    {
        return (string) $value !== '' ? 'set' : '(unset)';
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $ok = (bool) ($report['ok'] ?? false);
        $message = (string) ($report['message'] ?? 'Realtime doctor');
        $ok ? $this->info($message) : $this->error($message);
        $this->newLine();

        if (($report['mode'] ?? '') === 'fake') {
            $this->warn('Fake realtime mode is enabled. Set DPLY_FAKE_REALTIME=false in production.');
            $this->newLine();
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
        }

        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        if ($checks !== []) {
            $this->newLine();
            $this->table(
                ['Check', 'OK', 'Detail'],
                array_map(fn (array $c): array => [
                    (string) ($c['name'] ?? ''),
                    ($c['ok'] ?? false) ? 'yes' : 'no',
                    (string) ($c['detail'] ?? ''),
                ], $checks),
            );
        }

        if (($report['mode'] ?? '') === 'production' && ! $this->option('probe') && $ok) {
            $this->newLine();
            $this->line('Tip: re-run with --probe to test a live KV write/read/delete.');
        }
    }
}
