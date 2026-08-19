<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\CloudDatabase;
use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Support\Servers\DatabaseEngineInfo;
use App\Support\Servers\ManagedDatabaseSizeCatalog;

/**
 * Shared console-action stream for managed DigitalOcean database / Valkey
 * creates. The provision job writes as it polls; the Resources modal can
 * attach to the same run so the operator sees live output.
 */
final class ManagedDatabaseProvisionConsole
{
    public const KIND = 'managed_db_provision';

    public const KIND_RESIZE = 'managed_db_resize';

    public static function label(CloudDatabase $database): string
    {
        if ($database->engine === CloudDatabase::ENGINE_REDIS) {
            return __('Provisioning managed Valkey …');
        }

        $engine = (string) (DatabaseEngineInfo::for($database->engine)['label'] ?? ucfirst($database->engine));

        return __('Provisioning managed :engine …', ['engine' => $engine]);
    }

    public static function ensure(Site $site, SiteBinding $binding, CloudDatabase $database, ?string $runId = null): ConsoleAction
    {
        $existingId = $runId ?: (string) ($binding->config['console_run_id'] ?? '');
        $run = $existingId !== '' ? ConsoleAction::query()->find($existingId) : null;

        if (! $run instanceof ConsoleAction) {
            $run = ConsoleAction::query()
                ->forSubject($site)
                ->ofKind(self::KIND)
                ->notDismissed()
                ->inFlight()
                ->orderByDesc('created_at')
                ->first();
        }

        if (! $run instanceof ConsoleAction) {
            $run = ConsoleAction::query()->create([
                'subject_type' => $site->getMorphClass(),
                'subject_id' => $site->id,
                'kind' => self::KIND,
                'status' => ConsoleAction::STATUS_RUNNING,
                'started_at' => now(),
                'label' => self::label($database),
                'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
            ]);
        } elseif ($run->status === ConsoleAction::STATUS_QUEUED) {
            $run->forceFill([
                'status' => ConsoleAction::STATUS_RUNNING,
                'started_at' => $run->started_at ?? now(),
                'label' => self::label($database),
            ])->save();
        } elseif ($run->label !== self::label($database)) {
            $run->forceFill(['label' => self::label($database)])->save();
        }

        self::remember($binding, (string) $run->id);

        return $run;
    }

    public static function remember(SiteBinding $binding, string $runId): void
    {
        $config = is_array($binding->config) ? $binding->config : [];
        if (($config['console_run_id'] ?? null) === $runId) {
            return;
        }

        $config['console_run_id'] = $runId;
        $binding->forceFill(['config' => $config])->save();
    }

    public static function emitter(ConsoleAction $run): ConsoleEmitter
    {
        return new ConsoleEmitter((string) $run->id);
    }

    public static function noteIfNew(ConsoleAction $run, string $source, string $line, string $level = ConsoleAction::LEVEL_INFO): void
    {
        $lines = $run->fresh()?->lines() ?? [];
        $last = $lines !== [] ? (string) ($lines[array_key_last($lines)]['line'] ?? '') : '';
        if ($last === $line) {
            return;
        }

        self::emitter($run)($line, $level, $source);
    }

    public static function createAccepted(ConsoleAction $run, CloudDatabase $database): void
    {
        self::noteIfNew($run, 'digitalocean', sprintf(
            'Create accepted · engine=%s version=%s region=%s size=%s id=%s',
            $database->backendEngineSlug(),
            $database->backendEngineVersion() ?? 'default',
            $database->region,
            $database->backendSizeSlug(),
            $database->backend_id ?: 'pending',
        ), ConsoleAction::LEVEL_STEP);
    }

    public static function poll(ConsoleAction $run, CloudDatabase $database, string $status, int $attempt, int $max): void
    {
        self::noteIfNew($run, 'digitalocean', sprintf(
            'Waiting for cluster to come online · status=%s · poll %d/%d · %s · %s',
            $status !== '' ? $status : 'unknown',
            $attempt,
            $max,
            $database->region,
            $database->backendSizeSlug(),
        ));
    }

    public static function online(ConsoleAction $run): void
    {
        self::emitter($run)->success(__('Cluster is online. Wiring connection variables.'), 'digitalocean');
    }

    public static function complete(ConsoleAction $run): void
    {
        $run->forceFill([
            'status' => ConsoleAction::STATUS_COMPLETED,
            'finished_at' => now(),
            'error' => null,
        ])->save();
    }

    public static function fail(ConsoleAction $run, string $error): void
    {
        self::emitter($run)->error($error, 'digitalocean');
        $run->forceFill([
            'status' => ConsoleAction::STATUS_FAILED,
            'finished_at' => now(),
            'error' => mb_substr($error, 0, 2000),
        ])->save();
    }

    public static function resizeLabel(string $size): string
    {
        return __('Resizing to :size …', ['size' => ManagedDatabaseSizeCatalog::label($size)]);
    }

    public static function ensureResize(Site $site, SiteBinding $binding, string $size, ?string $runId = null): ConsoleAction
    {
        $existingId = $runId ?: (string) ($binding->config['console_run_id'] ?? '');
        $run = $existingId !== '' ? ConsoleAction::query()->find($existingId) : null;

        if ($run instanceof ConsoleAction && $run->kind !== self::KIND_RESIZE) {
            $run = null;
        }

        if (! $run instanceof ConsoleAction) {
            $run = ConsoleAction::query()
                ->forSubject($site)
                ->ofKind(self::KIND_RESIZE)
                ->notDismissed()
                ->inFlight()
                ->orderByDesc('created_at')
                ->first();
        }

        if (! $run instanceof ConsoleAction) {
            $run = ConsoleAction::query()->create([
                'subject_type' => $site->getMorphClass(),
                'subject_id' => $site->id,
                'kind' => self::KIND_RESIZE,
                'status' => ConsoleAction::STATUS_RUNNING,
                'started_at' => now(),
                'label' => self::resizeLabel($size),
                'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
            ]);
        } elseif ($run->status === ConsoleAction::STATUS_QUEUED) {
            $run->forceFill([
                'status' => ConsoleAction::STATUS_RUNNING,
                'started_at' => $run->started_at ?? now(),
                'label' => $run->label ?: self::resizeLabel($size),
            ])->save();
        }

        self::remember($binding, (string) $run->id);

        return $run;
    }

    public static function resizeAccepted(ConsoleAction $run, CloudDatabase $database, string $from, string $to): void
    {
        self::noteIfNew($run, 'digitalocean', sprintf(
            'Resize accepted · %s → %s · region=%s id=%s',
            $from !== '' ? $from : $database->backendSizeSlug(),
            $to,
            $database->region,
            $database->backend_id ?: 'pending',
        ), ConsoleAction::LEVEL_STEP);
    }

    public static function resizePoll(ConsoleAction $run, CloudDatabase $database, string $status, string $size, int $attempt, int $max): void
    {
        self::noteIfNew($run, 'digitalocean', sprintf(
            'Waiting for resize to finish · status=%s · poll %d/%d · %s',
            $status !== '' ? $status : 'unknown',
            $attempt,
            $max,
            $size !== '' ? $size : $database->backendSizeSlug(),
        ));
    }
}
