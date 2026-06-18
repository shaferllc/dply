<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\FunctionAction;
use App\Models\Server;
use App\Modules\Serverless\Services\Backends\ServerlessSequenceBackend;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Deploys an OpenWhisk sequence action.
 *
 * A sequence has no artifact to build — it is created directly through the
 * OpenWhisk REST API as an action with `exec.kind = sequence` and an ordered
 * `exec.components` list of fully-qualified action names. This is the
 * deploy-side counterpart to {@see ServerlessSequenceBuilder}.
 */
class ServerlessSequenceDeployer implements ServerlessSequenceBackend
{
    /**
     * Push a sequence action to its host's OpenWhisk namespace.
     *
     * @return array{ok: bool, error: ?string}
     */
    /** @return array<string, mixed> */
    public function deploy(FunctionAction $sequence): array
    {
        if (! $sequence->isSequence()) {
            return ['ok' => false, 'error' => 'This action is not a sequence.'];
        }

        $sequence->loadMissing('site.server');
        $credentials = $this->credentials($sequence->site?->server);
        if ($credentials === null) {
            return ['ok' => false, 'error' => 'The function host is not provisioned yet.'];
        }

        $components = $this->componentNames($sequence);
        if (count($components) < 2) {
            return ['ok' => false, 'error' => 'A sequence must chain at least two actions.'];
        }

        try {
            Http::withBasicAuth($credentials['key_id'], $credentials['key_secret'])
                ->acceptJson()
                ->put(
                    $credentials['api_host'].'/api/v1/namespaces/_/actions/'.rawurlencode((string) $sequence->name).'?overwrite=true',
                    [
                        'exec' => [
                            'kind' => 'sequence',
                            'components' => $components,
                        ],
                        'annotations' => [
                            ['key' => 'managed-by', 'value' => 'dply'],
                            ['key' => 'web-export', 'value' => true],
                        ],
                    ],
                );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * The sequence's components as fully-qualified OpenWhisk action names.
     *
     * @return list<string>
     */
    private function componentNames(FunctionAction $sequence): array
    {
        $components = ($sequence->components );

        return array_values(array_filter(array_map(
            static function (mixed $component): ?string {
                $name = is_array($component) ? trim((string) ($component['name'] ?? '')) : '';

                return $name !== '' ? '/_/'.$name : null;
            },
            $components,
        )));
    }

    /**
     * @return array{api_host: string, key_id: string, key_secret: string}|null
     */
    private function credentials(?Server $server): ?array
    {
        if (! $server instanceof Server || ! $server->isDigitalOceanFunctionsHost()) {
            return null;
        }

        $cfg = is_array($server->meta['digitalocean_functions'] ?? null) ? $server->meta['digitalocean_functions'] : [];
        $apiHost = rtrim((string) ($cfg['api_host'] ?? ''), '/');
        $accessKey = (string) ($cfg['access_key'] ?? '');

        if ($apiHost === '' || ! str_contains($accessKey, ':')) {
            return null;
        }

        [$keyId, $keySecret] = explode(':', $accessKey, 2);

        return ['api_host' => $apiHost, 'key_id' => $keyId, 'key_secret' => $keySecret];
    }
}
