<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\DigitalOceanService;
use Closure;
use Throwable;

/**
 * Manages a serverless function's scheduled triggers.
 *
 * DigitalOcean reserves the OpenWhisk `whisk.system/alarms` feed for the
 * cluster operator — a tenant can't bind it — so cron scheduling is done
 * through DigitalOcean's own v2 Functions triggers API instead. A DO
 * scheduled trigger is a single object: cron → function (no separate
 * OpenWhisk rule). Cron is evaluated in UTC.
 *
 * This service resolves the host's DO credential + namespace and wraps the
 * calls in a normalized {ok, error, …} result so the Platform panel can
 * render an error inline rather than fataling.
 */
class FunctionScheduleService
{
    /** Preset key → {label, cron}. Cron expressions are UTC. */
    public const PRESETS = [
        '5min' => ['label' => 'Every 5 minutes', 'cron' => '*/5 * * * *'],
        '15min' => ['label' => 'Every 15 minutes', 'cron' => '*/15 * * * *'],
        'hourly' => ['label' => 'Hourly', 'cron' => '0 * * * *'],
        'daily' => ['label' => 'Daily · 00:00 UTC', 'cron' => '0 0 * * *'],
        'weekly' => ['label' => 'Weekly · Mon 00:00 UTC', 'cron' => '0 0 * * 1'],
    ];

    /** Auto-name for a preset's trigger — also how the UI matches "Added". */
    public function presetTriggerName(string $key): string
    {
        return 'dply-'.$key;
    }

    /** Stable auto-name for a custom-cron trigger (same cron → same name). */
    public function customTriggerName(string $cron): string
    {
        return 'dply-cron-'.substr(md5(trim($cron)), 0, 8);
    }

    /**
     * @return array{ok: bool, error: ?string, triggers: list<array<string, mixed>>}
     */
    /** @return array<string, mixed> */
    public function list(Site $site): array
    {
        return $this->run(
            $site,
            fn (DigitalOceanService $do, string $ns): array => ['triggers' => $do->functionTriggers($ns)],
            ['triggers' => []],
        );
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    /** @return array<string, mixed> */
    public function add(Site $site, string $name, string $cron): array
    {
        $function = $this->actionName($site);
        if ($function === '') {
            return ['ok' => false, 'error' => __('Deploy this function before scheduling it.')];
        }

        return $this->run($site, function (DigitalOceanService $do, string $ns) use ($name, $function, $cron): array {
            $do->createScheduledFunctionTrigger($ns, $name, $function, trim($cron));

            return [];
        });
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    /** @return array<string, mixed> */
    public function remove(Site $site, string $name): array
    {
        return $this->run($site, function (DigitalOceanService $do, string $ns) use ($name): array {
            $do->deleteFunctionTrigger($ns, $name);

            return [];
        });
    }

    /**
     * Resolve the host's DO credential + Functions namespace, run the
     * callback, and normalize the outcome. Never throws.
     *
     * @param  array<string, mixed> $callback
     * @param  array<string, mixed> $emptyExtra  shape returned alongside an error
     * @return array<string, mixed>
     */
    private function run(Site $site, Closure $callback, array $emptyExtra = []): array
    {
        $site->loadMissing('server');
        $server = $site->server;
        $namespace = trim((string) data_get($server?->meta, 'digitalocean_functions.namespace', ''));
        $credential = $server?->providerCredential;

        if (! $credential instanceof ProviderCredential || $namespace === '') {
            return ['ok' => false, 'error' => __('The function host is not provisioned yet.')] + $emptyExtra;
        }

        try {
            $extra = $callback(new DigitalOceanService($credential), $namespace);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()] + $emptyExtra;
        }

        return ['ok' => true, 'error' => null] + $extra;
    }

    private function actionName(Site $site): string
    {
        $cfg = $site->serverlessConfig();
        $name = trim((string) ($cfg['action_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $url = trim((string) ($cfg['action_url'] ?? ''));

        return $url === '' ? '' : basename(rtrim($url, '/'));
    }
}
