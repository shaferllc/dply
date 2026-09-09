<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;

/**
 * Reconciled snapshot of what physically landed on a server during
 * provisioning, vs what the wizard requested.
 *
 * The bash provisioning script emits one `[dply-installed-stack] {json}`
 * line at the end of a successful run; the observer parses it into this
 * value object and persists the array form under `server.meta.installed_stack`.
 *
 * Consumers (scaffolding pipelines, UI panels, CLI commands) MUST go
 * through `fromMeta()` instead of reading `server.meta.database` directly,
 * because that's the wizard *request* — which can diverge from reality
 * when the script falls back (e.g., MySQL → SQLite under low-memory mode).
 *
 * `fromMeta` falls back to the wizard meta when `installed_stack` is
 * absent (legacy servers provisioned before reconciliation shipped),
 * so the value object always returns *something* sensible.
 */
final readonly class InstalledStack
{
    public const META_KEY = 'installed_stack';

    public function __construct(
        public ?string $database,
        public ?string $databaseVersion,
        public ?string $phpVersion,
        public ?string $webserver,
        public ?string $cacheService,
        public bool $lowMemoryMode,
        public ?int $totalMemoryMb,
        public ?int $swapMb,
    ) {}

    /** @param  array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            database: self::stringOrNull($data['database'] ?? null),
            databaseVersion: self::stringOrNull($data['database_version'] ?? null),
            phpVersion: self::stringOrNull($data['php_version'] ?? null),
            webserver: self::stringOrNull($data['webserver'] ?? null),
            cacheService: self::stringOrNull($data['cache_service'] ?? null),
            lowMemoryMode: (bool) ($data['low_mem_mode'] ?? false),
            totalMemoryMb: self::intOrNull($data['total_memory_mb'] ?? null),
            swapMb: self::intOrNull($data['swap_mb'] ?? null),
        );
    }

    /**
     * Resolve the installed-stack snapshot for a server.
     *
     * Reads `server.meta.installed_stack` if present (a real reconciled
     * snapshot from a recent provisioning run). Falls back to the wizard
     * meta keys (`meta.database`, `meta.php_version`, etc.) for legacy
     * servers where reconciliation never ran. The fallback IS the
     * migration — see Question 9 of the design doc.
     */
    public static function fromMeta(Server $server): self
    {
        $meta = $server->meta ?? [];

        if (is_array($meta[self::META_KEY] ?? null)) {
            return self::fromArray($meta[self::META_KEY]);
        }

        return new self(
            database: self::stringOrNull($meta['database'] ?? null),
            databaseVersion: null,
            phpVersion: self::stringOrNull($meta['php_version'] ?? null),
            webserver: self::stringOrNull($meta['webserver'] ?? null),
            cacheService: self::stringOrNull($meta['cache_service'] ?? null),
            lowMemoryMode: false,
            totalMemoryMb: null,
            swapMb: null,
        );
    }

    /**
     * Extract the snapshot from a task's stdout if a tagged line is present.
     *
     * Looks for `[dply-installed-stack] {json...}` (the same shape as
     * `[dply-verify]` and `[dply-rollback]` tagged lines). Returns null
     * if absent or malformed; observer treats null as "no reconciliation
     * yet" and leaves existing meta untouched.
     */
    public static function parseFromOutput(string $output): ?self
    {
        if (! preg_match('/\[dply-installed-stack\]\s*(\{.*\})\s*$/m', $output, $matches)) {
            return null;
        }

        $decoded = json_decode($matches[1], true);
        if (! is_array($decoded)) {
            return null;
        }

        return self::fromArray($decoded);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'database' => $this->database,
            'database_version' => $this->databaseVersion,
            'php_version' => $this->phpVersion,
            'webserver' => $this->webserver,
            'cache_service' => $this->cacheService,
            'low_mem_mode' => $this->lowMemoryMode,
            'total_memory_mb' => $this->totalMemoryMb,
            'swap_mb' => $this->swapMb,
        ];
    }

    /**
     * Version each wizard engine id promises, as a prefix of what the engine's
     * own CLI reports. Explicit rather than parsed out of the id: "mariadb114"
     * is 11.4 but "mariadb1011" is 10.11, and no digit-splitting rule gets both.
     *
     * @var array<string, string>
     */
    private const REQUESTED_VERSIONS = [
        'mysql84' => '8.4',
        'mysql80' => '8.0',
        'mysql57' => '5.7',
        'mariadb114' => '11.4',
        'mariadb11' => '11',
        'mariadb1011' => '10.11',
        'postgres18' => '18',
        'postgres17' => '17',
        'postgres16' => '16',
        'postgres15' => '15',
        'postgres14' => '14',
    ];

    /**
     * True when the wizard-requested database differs from what physically
     * landed. Used by UI panels to render the "Requested vs Installed"
     * divergence section.
     *
     * Two comparisons, in order:
     *
     *  1. Family. A raw string compare used to be the whole test, which meant
     *     every probed server looked divergent: the wizard records a versioned
     *     id ("mysql84") but {@see \App\Services\Servers\ServerInventoryProbeScript}
     *     can only observe a family plus a live version ("mysql" + "8.0.46"),
     *     and rewrites the snapshot that way on the first inventory run.
     *     Family compare keeps the real fallback (mysql84 -> sqlite3 under
     *     low-memory mode) firing while dropping that noise.
     *
     *  2. Version, when the request names one and the snapshot carries one.
     *     Same family is not the same engine: mysql84 satisfied by 8.0.46 is
     *     exactly the pin-failed case the banner exists to report.
     */
    public function divergesFromRequest(Server $server): bool
    {
        $requested = $server->meta['database'] ?? null;

        if (! is_string($requested) || $requested === '' || $this->database === null) {
            return false;
        }

        if (DatabaseWorkspaceEngines::family($requested) !== DatabaseWorkspaceEngines::family($this->database)) {
            return true;
        }

        $wanted = self::REQUESTED_VERSIONS[$requested] ?? null;

        if ($wanted === null || $this->databaseVersion === null || $this->databaseVersion === '') {
            return false;
        }

        return $this->databaseVersion !== $wanted
            && ! str_starts_with($this->databaseVersion, $wanted.'.');
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
