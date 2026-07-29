<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Services;

use App\Modules\Realtime\Models\RealtimeApp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * dply-managed realtime backend. Provisions an app by writing its credential
 * record into the realtime Worker's APPS KV namespace, and reads peak-concurrent
 * usage from the Worker's authenticated /stats endpoint.
 */
class CloudflareRealtimeBackend implements RealtimeBackend
{
    public function __construct(
        private readonly RealtimeCloudflareClient $client,
    ) {}

    public static function fromConfig(): self
    {
        return new self(RealtimeCloudflareClient::fromConfig());
    }

    public function providerKey(): string
    {
        return 'dply_realtime';
    }

    public function provision(RealtimeApp $app): void
    {
        $namespaceId = $this->namespaceId();
        $record = json_encode($app->kvRecord(), JSON_THROW_ON_ERROR);

        // Two pointers: by-key (connect lookup) and by-id (publish/stats lookup).
        $this->client->putKvValue($namespaceId, $app->kvKeyByKey(), $record);
        $this->client->putKvValue($namespaceId, $app->kvKeyById(), $record);
    }

    public function deprovision(RealtimeApp $app): void
    {
        $namespaceId = $this->namespaceId();
        $this->client->deleteKvValue($namespaceId, $app->kvKeyByKey());
        $this->client->deleteKvValue($namespaceId, $app->kvKeyById());
    }

    public function fetchPeakConnections(RealtimeApp $app): ?int
    {
        return $this->fetchStats($app)['peakConnections'] ?? null;
    }

    public function fetchStats(RealtimeApp $app): ?array
    {
        $response = Http::withHeaders($app->statsAuthHeaders())
            ->acceptJson()
            ->get($app->statsEndpoint());

        if (! $response->successful()) {
            Log::warning('realtime_stats_fetch_failed', [
                'realtime_app_id' => $app->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $peak = $response->json('peakConnections');
        if (! is_numeric($peak)) {
            return null;
        }

        $current = $response->json('connections');

        return [
            'connections' => is_numeric($current) ? (int) $current : 0,
            'peakConnections' => (int) $peak,
        ];
    }

    public function resetPeakConnections(RealtimeApp $app): void
    {
        Http::withHeaders($app->statsAuthHeaders())
            ->acceptJson()
            ->post($app->statsEndpoint().'/reset');
    }

    private function namespaceId(): string
    {
        $id = (string) config('realtime.cloudflare.kv_namespace_id');
        if ($id === '') {
            throw new RuntimeException(
                'DPLY_REALTIME_CF_KV_NAMESPACE_ID is not set — cannot provision realtime apps.'
            );
        }

        return $id;
    }
}
