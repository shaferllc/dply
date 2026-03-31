<?php

namespace App\Services\Servers;

use App\Models\ServerMetricSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * POSTs a stored snapshot to the configured remote ingest API (queue worker).
 */
class ServerMetricsIngestClient
{
    public function send(ServerMetricSnapshot $snapshot): void
    {
        $ingest = config('server_metrics.ingest', []);
        if (! ($ingest['enabled'] ?? false)) {
            return;
        }

        $url = $ingest['url'] ?? '';
        if (! is_string($url) || $url === '') {
            return;
        }

        $server = $snapshot->server;
        if ($server === null) {
            return;
        }

        $body = [
            'snapshot_id' => $snapshot->id,
            'server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'server_name' => $server->name,
            'captured_at' => $snapshot->captured_at->toIso8601String(),
            'metrics' => $snapshot->payload,
        ];

        $timeout = (int) ($ingest['timeout'] ?? 15);
        $request = Http::timeout($timeout)->acceptJson();

        $token = $ingest['token'] ?? null;
        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post($url, $body);

        if (! $response->successful()) {
            Log::warning('server_metrics.ingest_failed', [
                'snapshot_id' => $snapshot->id,
                'server_id' => $server->id,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw new \RuntimeException('Metrics ingest HTTP '.$response->status());
        }
    }
}
