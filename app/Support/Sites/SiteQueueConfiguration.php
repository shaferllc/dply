<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * What queue driver this site's app is actually configured to use.
 *
 * The failure this exists for: a Supervisor worker running against
 * `QUEUE_CONNECTION=sync` consumes nothing, because `sync` executes jobs inline
 * at dispatch and never writes to a store. The worker reports RUNNING, the
 * queue page shows depth 0, and every reading looks healthy — the queue is
 * simply not a queue. Nothing on the box can tell you that; only the env can.
 *
 * Read from dply's own copy of the env, never over SSH: this is consulted while
 * rendering, and a render-path SSH is a 30-second timeout waiting to happen.
 */
final class SiteQueueConfiguration
{
    /**
     * Drivers that persist a job somewhere a worker can pick it up.
     *
     * @var list<string>
     */
    private const ASYNC_DRIVERS = ['database', 'redis', 'sqs', 'beanstalkd', 'rabbitmq', 'kafka', 'dply'];

    public function __construct(
        public readonly ?string $connection,
        public readonly bool $isConfigured,
        public readonly bool $isSync,
    ) {}

    public static function for(Site $site): self
    {
        // What the APP said about itself, if a canary has ever run. dply's copy
        // of the .env can be stale — someone edits it on the box, or a deploy
        // pushed a value dply never recorded — and a readiness panel insisting
        // on `sync` while a green round trip says `redis` is worse than no
        // panel. An observation beats a record.
        $observed = strtolower(trim((string) data_get($site->meta, 'queue_observed.driver', '')));

        if ($observed !== '') {
            return new self($observed, in_array($observed, self::ASYNC_DRIVERS, true), $observed === 'sync');
        }

        $env = self::parse($site->effectiveEnvFileContent());
        $connection = strtolower(trim((string) ($env['QUEUE_CONNECTION'] ?? '')));

        if ($connection === '') {
            // Laravel's own default when the key is absent is `sync`, so an
            // unset value is not "unknown" — it is the broken case, and saying
            // so is the whole point of this class.
            return new self(null, false, true);
        }

        return new self(
            $connection,
            in_array($connection, self::ASYNC_DRIVERS, true),
            $connection === 'sync',
        );
    }

    /**
     * The driver to switch a `sync` site onto, chosen from what it already has.
     *
     * Order matters and is not arbitrary. A site with a redis binding already
     * has the fastest option provisioned and connected, so handing it the
     * database driver would add queue churn to its primary database for no
     * reason. `database` is the fallback because every site has one and Laravel
     * ships the migration — it is slower, but it works everywhere and provisions
     * nothing.
     *
     * Returns null when neither is available, which is a real state: a static
     * site or one whose resources have not been bound yet has nothing to switch
     * TO, and offering a button that cannot work is worse than offering none.
     */
    public static function suggestedDriverFor(Site $site): ?string
    {
        $site->loadMissing('bindings');

        $hasRedis = $site->bindings->contains(
            static fn ($binding): bool => in_array($binding->type ?? null, ['redis', 'cache'], true)
        );

        if ($hasRedis) {
            return 'redis';
        }

        $hasDatabase = $site->bindings->contains(
            static fn ($binding): bool => ($binding->type ?? null) === 'database'
        );

        return $hasDatabase ? 'database' : null;
    }

    /** A single sentence for the page, or null when there is nothing wrong. */
    public function warning(): ?string
    {
        if ($this->isSync) {
            return __('This app runs jobs inline (QUEUE_CONNECTION=:c). Workers here will consume nothing until it points at a real queue driver.', [
                'c' => $this->connection ?? 'sync',
            ]);
        }

        if (! $this->isConfigured) {
            return __('QUEUE_CONNECTION is set to ":c", which dply does not recognise as a queue driver. Jobs may not reach a worker.', [
                'c' => (string) $this->connection,
            ]);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $content): array
    {
        $vars = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            // Values are commonly quoted; the quotes are syntax, not value.
            $vars[$m[1]] = trim(trim($m[2]), "\"'");
        }

        return $vars;
    }
}
