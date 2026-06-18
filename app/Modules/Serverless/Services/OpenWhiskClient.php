<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A thin, shared client over the OpenWhisk REST API that DigitalOcean
 * Functions exposes (https://openwhisk.apache.org/documentation.html#rest-api).
 *
 * Built from a Functions-backed Server — it reads the per-host credentials
 * from `meta['digitalocean_functions']` (the same shape `FunctionInvoker`
 * and the action deployer use). Every call goes to the `_` namespace
 * placeholder (the credentials resolve it), and every method returns a
 * normalized {ok, error, data} array — it never throws, so callers (the
 * Platform workspace tab) can render an error panel uniformly.
 *
 * This is the reusable client the OpenWhisk calls scattered across the
 * deploy services never had; the broader serverless roadmap can adopt it.
 */
class OpenWhiskClient
{
    private string $apiHost = '';

    private string $keyId = '';

    private string $keySecret = '';

    public readonly bool $configured;

    public function __construct(?Server $server)
    {
        $cfg = ($server instanceof Server && $server->isDigitalOceanFunctionsHost())
            ? (is_array($server->meta['digitalocean_functions'] ?? null) ? $server->meta['digitalocean_functions'] : [])
            : [];

        $apiHost = rtrim((string) ($cfg['api_host'] ?? ''), '/');
        $accessKey = (string) ($cfg['access_key'] ?? '');

        if ($apiHost !== '' && str_contains($accessKey, ':')) {
            [$this->keyId, $this->keySecret] = explode(':', $accessKey, 2);
            $this->apiHost = $apiHost;
            $this->configured = true;
        } else {
            $this->configured = false;
        }
    }

    // ── Actions ──────────────────────────────────────────────────────────

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function actions(): array
    {
        return $this->request('GET', 'actions', ['limit' => 200]);
    }

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function action(string $name): array
    {
        return $this->request('GET', 'actions/'.rawurlencode($name));
    }

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function deleteAction(string $name): array
    {
        return $this->request('DELETE', 'actions/'.rawurlencode($name));
    }

    // ── Packages ─────────────────────────────────────────────────────────

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function packages(): array
    {
        return $this->request('GET', 'packages', ['limit' => 200]);
    }

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function package(string $name): array
    {
        return $this->request('GET', 'packages/'.rawurlencode($name));
    }

    // ── Triggers ─────────────────────────────────────────────────────────

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function triggers(): array
    {
        // docs=true so the list carries each trigger's parameters.
        return $this->request('GET', 'triggers', ['limit' => 200, 'docs' => 'true']);
    }

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function trigger(string $name): array
    {
        return $this->request('GET', 'triggers/'.rawurlencode($name));
    }

    /**
     * Create or update a trigger. `$params` is a flat key→value map; it is
     * converted to OpenWhisk's `{key, value}` parameter list.
     *
     * @param  array<string, mixed> $params
     * @return array{ok: bool, error: ?string, data: mixed}
     */
    /** @return array<string, mixed> */
    public function putTrigger(string $name, array $params = []): array
    {
        $body = $params === [] ? [] : ['parameters' => $this->keyValuePairs($params)];

        return $this->request('PUT', 'triggers/'.rawurlencode($name), ['overwrite' => 'true'], $body);
    }

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function deleteTrigger(string $name): array
    {
        return $this->request('DELETE', 'triggers/'.rawurlencode($name));
    }

    /**
     * Fire a trigger once with an optional JSON payload.
     *
     * @param  array<string, mixed> $payload
     * @param  array<string, mixed> $params
     * @return array{ok: bool, error: ?string, data: mixed}
     */
    /** @return array<string, mixed> */
    public function fireTrigger(string $name, array $payload = []): array
    {
        return $this->request('POST', 'triggers/'.rawurlencode($name), [], $payload);
    }

    // ── Rules ────────────────────────────────────────────────────────────

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // docs=true so the list carries each rule's status + trigger/action.
        return $this->request('GET', 'rules', ['limit' => 200, 'docs' => 'true']);
    }

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function rule(string $name): array
    {
        return $this->request('GET', 'rules/'.rawurlencode($name));
    }

    /**
     * Create or update a rule binding a trigger to an action.
     *
     * @param  array<string, mixed> $payload
     * @return array{ok: bool, error: ?string, data: mixed}
     */
    /** @return array<string, mixed> */
    public function putRule(string $name, string $trigger, string $action): array
    {
        return $this->request('PUT', 'rules/'.rawurlencode($name), ['overwrite' => 'true'], [
            'trigger' => $trigger,
            'action' => $action,
        ]);
    }

    /** @return array{ok: bool, error: ?string, data: mixed} */
    /** @return array<string, mixed> */
    public function deleteRule(string $name): array
    {
        return $this->request('DELETE', 'rules/'.rawurlencode($name));
    }

    /**
     * Enable or disable a rule — `$state` is `active` or `inactive`.
     *
     * @return array{ok: bool, error: ?string, data: mixed}
     */
    /** @return array<string, mixed> */
    public function setRuleState(string $name, string $state): array
    {
        return $this->request('POST', 'rules/'.rawurlencode($name), [], [
            'status' => $state === 'active' ? 'active' : 'inactive',
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Issue one OpenWhisk REST call and normalize the outcome. Never throws.
     *
     * @param  array<string, mixed> $query
     * @param  array<string, mixed> $body
     * @return array{ok: bool, error: ?string, data: mixed}
     */
    private function request(string $method, string $path, array $query = [], array $body = []): array
    {
        if (! $this->configured) {
            return ['ok' => false, 'error' => 'The function host is not provisioned yet.', 'data' => null];
        }

        $url = $this->apiHost.'/api/v1/namespaces/_/'.ltrim($path, '/');
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        try {
            $http = Http::withBasicAuth($this->keyId, $this->keySecret)->acceptJson()->timeout(20);
            $response = match (strtoupper($method)) {
                'GET' => $http->get($url),
                'DELETE' => $http->delete($url),
                'PUT' => $http->put($url, $body),
                'POST' => $http->post($url, $body),
                default => throw new \InvalidArgumentException('Unsupported method: '.$method),
            };
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'data' => null];
        }

        if (! $response->successful()) {
            $detail = is_array($response->json()) ? (string) ($response->json()['error'] ?? '') : '';

            return [
                'ok' => false,
                'error' => trim('OpenWhisk returned HTTP '.$response->status().'. '.$detail),
                'data' => $response->json(),
            ];
        }

        return ['ok' => true, 'error' => null, 'data' => $response->json()];
    }

    /**
     * Convert a flat map to OpenWhisk's `[{key, value}, …]` parameter shape.
     *
     * @param  array<string, mixed> $assoc
     * @return list<array{key: string, value: mixed}>
     */
    private function keyValuePairs(array $assoc): array
    {
        $pairs = [];
        foreach ($assoc as $key => $value) {
            $pairs[] = ['key' => (string) $key, 'value' => $value];
        }

        return $pairs;
    }
}
