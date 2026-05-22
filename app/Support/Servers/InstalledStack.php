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

    /** @param  array<string,mixed>  $data */
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
     * True when the wizard-requested database differs from what physically
     * landed. Used by UI panels to render the "Requested vs Installed"
     * divergence section.
     */
    public function divergesFromRequest(Server $server): bool
    {
        $requested = $server->meta['database'] ?? null;

        return is_string($requested) && $this->database !== null && $requested !== $this->database;
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
