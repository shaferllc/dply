<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;

/**
 * Durable maintenance for Functions Laravel apps.
 *
 * Laravel's `storage/framework/down` lives in `/tmp` on a function and is
 * lost on cold start. dply instead binds `__dply_maintenance` on the action
 * (live, no zip) and mirrors `DPLY_MAINTENANCE` in the managed env (next
 * deploy). The injected handler reads either signal.
 */
class ServerlessMaintenance
{
    public function __construct(
        private readonly ServerlessFunctionConfigurator $configurator,
    ) {}

    public function enabled(Site $site): bool
    {
        $maintenance = $site->serverlessConfig()['maintenance'] ?? [];

        if (is_array($maintenance)) {
            return (bool) ($maintenance['enabled'] ?? false);
        }

        return (bool) $maintenance;
    }

    /**
     * @return array{ok: bool, applied: bool, error: ?string}
     */
    public function setEnabled(Site $site, bool $enabled): array
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['maintenance'] = [
            'enabled' => $enabled,
            'updated_at' => now()->toIso8601String(),
        ];
        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);

        $this->writeMaintenanceEnv($site, $enabled);

        $result = $this->configurator->apply($site->fresh() ?? $site);

        return [
            'ok' => (bool) $result['ok'],
            'applied' => (bool) $result['applied'],
            'error' => $result['error'] ?? null,
        ];
    }

    private function writeMaintenanceEnv(Site $site, bool $enabled): void
    {
        $content = (string) $site->env_file_content;
        $lines = $content === '' ? [] : (preg_split('/\r\n|\r|\n/', $content) ?: []);
        $entry = 'DPLY_MAINTENANCE='.($enabled ? '1' : '0');
        $replaced = false;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*DPLY_MAINTENANCE\s*=/', (string) $line) === 1) {
                $lines[$index] = $entry;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $lines[] = $entry;
        }

        $site->forceFill(['env_file_content' => implode("\n", $lines)])->save();
    }
}
