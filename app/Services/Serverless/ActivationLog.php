<?php

declare(strict_types=1);

namespace App\Services\Serverless;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads recent OpenWhisk activation records for a serverless function so the
 * workspace can show runtime logs + results — the data behind the
 * `serverless:logs` command, surfaced in the UI.
 */
class ActivationLog
{
    /**
     * @return array{ok: bool, error: ?string, activations: list<array{id: string, name: string, status: string, success: bool, duration: int, start: int, cold: bool, logs: list<string>, result: mixed}>}
     */
    public function recent(Site $site, int $limit = 25): array
    {
        $site->loadMissing('server');
        $server = $site->server;

        if (! $server instanceof Server || ! $server->isDigitalOceanFunctionsHost()) {
            return ['ok' => false, 'error' => 'This site is not a DigitalOcean Functions host.', 'activations' => []];
        }

        $cfg = is_array($server->meta['digitalocean_functions'] ?? null) ? $server->meta['digitalocean_functions'] : [];
        $apiHost = rtrim((string) ($cfg['api_host'] ?? ''), '/');
        $accessKey = (string) ($cfg['access_key'] ?? '');

        if ($apiHost === '' || ! str_contains($accessKey, ':')) {
            return ['ok' => false, 'error' => 'The function host is not provisioned yet.', 'activations' => []];
        }

        [$keyId, $keySecret] = explode(':', $accessKey, 2);

        try {
            $response = Http::withBasicAuth($keyId, $keySecret)
                ->acceptJson()
                ->timeout(20)
                ->get($apiHost.'/api/v1/namespaces/_/activations', ['limit' => $limit, 'docs' => 'true']);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'activations' => []];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'Activations API returned HTTP '.$response->status().'.', 'activations' => []];
        }

        $activations = [];
        foreach ((array) $response->json() as $activation) {
            if (! is_array($activation)) {
                continue;
            }
            // OpenWhisk records an `initTime` annotation only when the
            // container had to be initialised — i.e. a cold start.
            $cold = false;
            foreach ((array) data_get($activation, 'annotations', []) as $annotation) {
                if (is_array($annotation) && ($annotation['key'] ?? null) === 'initTime'
                    && (int) ($annotation['value'] ?? 0) > 0) {
                    $cold = true;
                    break;
                }
            }

            $activations[] = [
                'id' => (string) ($activation['activationId'] ?? ''),
                'name' => (string) ($activation['name'] ?? ''),
                'status' => (string) data_get($activation, 'response.status', 'unknown'),
                'success' => (bool) data_get($activation, 'response.success', false),
                'duration' => (int) ($activation['duration'] ?? 0),
                'start' => (int) ($activation['start'] ?? 0),
                'cold' => $cold,
                'logs' => array_values(array_filter((array) data_get($activation, 'logs', []), 'is_string')),
                'result' => data_get($activation, 'response.result'),
            ];
        }

        return ['ok' => true, 'error' => null, 'activations' => $activations];
    }
}
