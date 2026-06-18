<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\FunctionAction;
use App\Models\Server;
use App\Modules\Serverless\Services\Backends\ServerlessTriggerBackend;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Provisions OpenWhisk scheduled (cron) triggers for a {@see FunctionAction}.
 *
 * DigitalOcean Functions exposes OpenWhisk's alarms feed: a cron-scheduled
 * trigger fires the action on a schedule without dply being the caller —
 * the real-trigger replacement for dply's minute-by-minute tick.
 *
 * Three OpenWhisk objects are wired per scheduled action, all through the
 * REST API (no `doctl`): a trigger, the alarms-feed binding that drives it
 * on schedule, and a rule binding the trigger to the action. The schedule
 * itself lives on `function_actions.trigger`:
 *
 *     { "cron": "*\/5 * * * *", "enabled": true }
 *
 * This service is purely additive — it does not yet replace the tick
 * subsystem; that retirement is a separate, reviewed change.
 */
class ServerlessTriggerProvisioner implements ServerlessTriggerBackend
{
    /** The built-in OpenWhisk alarms feed action. */
    private const ALARM_FEED = '/whisk.system/alarms/alarm';

    /**
     * Create (or update) the scheduled trigger for an action. When the
     * action has no enabled cron schedule this tears any existing trigger
     * down instead, so the call is idempotent against the desired state.
     *
     * @return array{ok: bool, error: ?string, trigger: ?string}
     */
    /** @return array<string, mixed> */
    public function provision(FunctionAction $action): array
    {
        $action->loadMissing('site.server');
        $server = $action->site?->server;

        $credentials = $this->credentials($server);
        if ($credentials === null) {
            return ['ok' => false, 'error' => 'The function host is not provisioned yet.', 'trigger' => null];
        }

        $cron = $this->cronExpression($action);
        if ($cron === null) {
            // No enabled schedule — make sure no stale trigger lingers.
            $this->remove($action);

            return ['ok' => true, 'error' => null, 'trigger' => null];
        }

        $triggerName = $this->triggerName($action);
        $ruleName = $this->ruleName($action);

        try {
            $http = Http::withBasicAuth($credentials['key_id'], $credentials['key_secret'])->acceptJson();
            $base = $credentials['api_host'].'/api/v1/namespaces/_';

            // 1. The bare trigger.
            $http->put($base.'/triggers/'.rawurlencode($triggerName), [
                'annotations' => [['key' => 'managed-by', 'value' => 'dply']],
            ]);

            // 2. Bind the alarms feed so the trigger fires on the cron.
            $http->post($credentials['api_host'].'/api/v1/namespaces/whisk.system/actions/alarms/alarm?blocking=true&result=true', [
                'lifecycleEvent' => 'CREATE',
                'triggerName' => '/_/'.$triggerName,
                'authKey' => $credentials['key_id'].':'.$credentials['key_secret'],
                'cron' => $cron,
            ]);

            // 3. The rule binding the trigger to the action.
            $http->put($base.'/rules/'.rawurlencode($ruleName), [
                'trigger' => '/_/'.$triggerName,
                'action' => '/_/'.$this->actionName($action),
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'trigger' => null];
        }

        return ['ok' => true, 'error' => null, 'trigger' => $triggerName];
    }

    /**
     * Tear down an action's scheduled trigger — rule, feed binding, trigger.
     *
     * @return array{ok: bool, error: ?string}
     */
    /** @return array<string, mixed> */
    public function remove(FunctionAction $action): array
    {
        $action->loadMissing('site.server');
        $credentials = $this->credentials($action->site?->server);
        if ($credentials === null) {
            return ['ok' => false, 'error' => 'The function host is not provisioned yet.'];
        }

        $triggerName = $this->triggerName($action);
        $ruleName = $this->ruleName($action);

        try {
            $http = Http::withBasicAuth($credentials['key_id'], $credentials['key_secret'])->acceptJson();
            $base = $credentials['api_host'].'/api/v1/namespaces/_';

            $http->delete($base.'/rules/'.rawurlencode($ruleName));
            $http->post($credentials['api_host'].'/api/v1/namespaces/whisk.system/actions/alarms/alarm?blocking=true&result=true', [
                'lifecycleEvent' => 'DELETE',
                'triggerName' => '/_/'.$triggerName,
                'authKey' => $credentials['key_id'].':'.$credentials['key_secret'],
            ]);
            $http->delete($base.'/triggers/'.rawurlencode($triggerName));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'error' => null];
    }

    /** OpenWhisk trigger name for an action — stable and namespace-unique. */
    public function triggerName(FunctionAction $action): string
    {
        return $this->actionName($action).'-dply-cron';
    }

    /** OpenWhisk rule name binding the trigger to the action. */
    public function ruleName(FunctionAction $action): string
    {
        return $this->actionName($action).'-dply-cron-rule';
    }

    /**
     * The enabled cron expression for an action, or null when it has no
     * active schedule.
     */
    private function cronExpression(FunctionAction $action): ?string
    {
        $trigger = ($action->trigger );
        $cron = trim((string) ($trigger['cron'] ?? ''));

        if ($cron === '' || ($trigger['enabled'] ?? false) !== true) {
            return null;
        }

        return $cron;
    }

    private function actionName(FunctionAction $action): string
    {
        return trim((string) $action->name) !== '' ? (string) $action->name : (string) $action->id;
    }

    /**
     * Resolve the host's OpenWhisk REST credentials.
     *
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
