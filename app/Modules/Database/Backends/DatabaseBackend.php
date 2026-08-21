<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Modules\Cloud\Backends\CloudBackend;

/**
 * A managed-database backend — a hosted provider dply can provision a
 * database cluster on and attach to a site (DigitalOcean Managed Databases
 * today; Vultr / AWS RDS / serverless vendors to come).
 *
 * Mirrors the Cloud module's {@see CloudBackend}
 * pattern: one interface, a {@see DatabaseRouter} that selects the concrete
 * implementation, so the modal and the provisioning job stay provider-blind.
 *
 * The Eloquent record is App\Models\CloudDatabase; lifecycle is asynchronous
 * (clusters take minutes), so {@see provision()} returns immediately and
 * {@see poll()} is called repeatedly until the cluster reports online.
 */
interface DatabaseBackend
{
    /** Create, delete and rotate users on the cluster. */
    public const CAP_USERS = 'users';

    /** Change the cluster plan in place. */
    public const CAP_RESIZE = 'resize';

    /** Report CPU / memory / disk time series for the cluster. */
    public const CAP_METRICS = 'metrics';

    /** List automatic backups and restore one into a new cluster. */
    public const CAP_BACKUPS = 'backups';

    /** Stable backend key persisted on CloudDatabase.backend (e.g. digitalocean_managed_database). */
    public function key(): string;

    /**
     * Whether this backend can perform a day-two operation — one of the
     * CAP_* constants above.
     *
     * Declared rather than probed: the detail page needs the answer on every
     * render, before any provider call, so an unsupported panel can say so
     * instead of rendering an empty one that reads as a failed load.
     */
    public function supports(string $capability): bool;

    /**
     * Engine slugs this backend can offer (postgres / mysql / redis …).
     *
     * @return list<string>
     */
    public function supportedEngines(): array;

    /**
     * The provider region slug this backend would use to co-locate a cluster
     * with $server, or null when the backend can't co-locate there.
     */
    public function regionForServer(Server $server): ?string;

    /**
     * Estimated monthly USD for a portable size tier (small/medium/large),
     * or null when unknown. Display-only — real billing flows through the
     * provider account (or the Billing module for cost-plus servers).
     */
    public function estimatedMonthlyCost(string $size): ?int;

    /**
     * Create the cluster for this row at the provider. Returns immediately;
     * the cluster is still spinning up. Stores backend_id on the row.
     */
    public function provision(CloudDatabase $database): void;

    /**
     * Poll the provider for the cluster's current state. Returns a normalized
     * shape; when status is `online` the connection block is populated.
     *
     * @return array{status: string, connection: array<string, mixed>}
     */
    public function poll(CloudDatabase $database): array;

    /**
     * Restrict network access to the cluster so only $server can reach it
     * (DO trusted-sources / RDS security-group / VPC). Best-effort: a backend
     * that can't lock down (e.g. a BYO serverless vendor with no IP allowlist)
     * may no-op. Safe to call repeatedly.
     */
    public function lockNetworkTo(CloudDatabase $database, Server $server): void;

    /**
     * Change the cluster plan in place. Backends that cannot resize throw.
     * Returns immediately; the cluster is still resizing until {@see poll()}
     * reports `online`.
     */
    public function resize(CloudDatabase $database, string $size): void;

    /**
     * The metrics this backend can chart for this database, in display order.
     *
     * Engine-dependent: a Valkey cluster has no disk-utilization series to
     * plot. `format` matches the spec x-metrics-line-chart understands
     * ('percent' | 'load' | 'bytes-per-sec').
     *
     * @return list<array{key: string, label: string, format: string}>
     */
    public function metricCatalog(CloudDatabase $database): array;

    /**
     * Raw datapoints for one metric over a UNIX-timestamp window.
     *
     * An empty list means "nothing to plot" — a cluster too young to have
     * datapoints is the common case and is not an error.
     *
     * @return list<array{t: int, v: float}>
     */
    public function metric(CloudDatabase $database, string $metric, int $start, int $end): array;

    /**
     * Automatic backups available to restore, newest first.
     *
     * @return list<array{created_at: string, size_gigabytes: float}>
     */
    public function backups(CloudDatabase $database): array;

    /**
     * Provision $target as a copy of $source seeded from the backup taken at
     * $backupCreatedAt. $source is never modified — restore always lands in a
     * new cluster, so a bad restore costs money rather than data.
     *
     * Returns immediately, like {@see provision()}; the caller polls $target.
     */
    public function provisionFromBackup(CloudDatabase $target, CloudDatabase $source, string $backupCreatedAt): void;
}
