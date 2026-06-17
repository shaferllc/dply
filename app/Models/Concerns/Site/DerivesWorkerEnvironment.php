<?php

namespace App\Models\Concerns\Site;

use App\Models\Site;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A worker is a ROLE of its parent app, not an independent site. It inherits the
 * parent's entire environment and overrides only a small role-specific set; it
 * therefore exposes no Environment or Resources UI of its own.
 *
 * The worker's own `env_file_content` holds ONLY its overrides (a handful of
 * keys); the effective env it deploys is the parent's full env with those
 * overrides applied. One env source → app/worker drift is structurally
 * impossible (it replaces the hand-sync that used to be needed).
 */
trait DerivesWorkerEnvironment
{
    /**
     * Keys a worker is allowed to override on top of its parent's env. Exact
     * matches plus the `HORIZON_` prefix (the worker's own Horizon tuning).
     * Kept deliberately small — "the parent, besides an app URL and the fact
     * that it's a worker."
     */
    public const WORKER_OVERRIDE_KEYS = [
        'APP_URL',
        'QUEUE_CONNECTION',
        'REDIS_QUEUE',
    ];

    /** @return BelongsTo<Site, $this> */
    public function parentSite(): BelongsTo {
        return $this->belongsTo(Site::class, 'parent_site_id');
    }

    /** True when this site derives its environment from a parent app. */
    public function isDerivedWorker(): bool
    {
        return $this->parent_site_id !== null;
    }

    /**
     * The site whose RESOURCE BINDINGS (database, redis, broadcasting, mail,
     * logging, storage) and their pushed config this site should use. A derived
     * worker inherits its parent app's resources — the server push composes
     * connection vars + logging from here, not just from static env — so the
     * worker mirrors the parent end-to-end. A standalone site uses its own.
     */
    public function resourceSourceSite(): self
    {
        if ($this->isDerivedWorker()) {
            $parent = $this->parentSite;
            if ($parent !== null) {
                return $parent;
            }
        }

        return $this;
    }

    public static function isWorkerOverrideKey(string $key): bool
    {
        return in_array($key, self::WORKER_OVERRIDE_KEYS, true)
            || str_starts_with($key, 'HORIZON_');
    }

    /**
     * The environment content this site actually deploys. For a derived worker
     * it's the parent's full env with this site's override keys applied on top;
     * for a standalone site it's its own env_file_content verbatim.
     */
    public function effectiveEnvFileContent(): string
    {
        $own = (string) ($this->env_file_content ?? '');

        if (! $this->isDerivedWorker()) {
            return $own;
        }

        $parent = $this->parentSite;
        if ($parent === null) {
            return $own;
        }

        // Parse this worker's own content for the allowed override keys only.
        $overrides = [];
        foreach (preg_split('/\r\n|\r|\n/', $own) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m) && self::isWorkerOverrideKey($m[1])) {
                $overrides[$m[1]] = $m[2];
            }
        }

        // Start from the parent's full env (preserving its structure), then
        // replace/append the worker's overrides.
        $lines = preg_split('/\r\n|\r|\n/', (string) ($parent->env_file_content ?? ''));
        $applied = [];
        foreach ($lines as &$line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $m) && array_key_exists($m[1], $overrides)) {
                $line = $m[1].'='.$overrides[$m[1]];
                $applied[$m[1]] = true;
            }
        }
        unset($line);
        foreach ($overrides as $k => $v) {
            if (! isset($applied[$k])) {
                $lines[] = $k.'='.$v;
            }
        }

        return implode("\n", $lines);
    }
}
