<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends;

use App\Models\CloudDatabase;
use App\Models\Server;

/**
 * A managed-database backend — a hosted provider dply can provision a
 * database cluster on and attach to a site (DigitalOcean Managed Databases
 * today; Vultr / AWS RDS / serverless vendors to come).
 *
 * Mirrors the Cloud module's {@see \App\Modules\Cloud\Backends\CloudBackend}
 * pattern: one interface, a {@see DatabaseRouter} that selects the concrete
 * implementation, so the modal and the provisioning job stay provider-blind.
 *
 * The Eloquent record is App\Models\CloudDatabase; lifecycle is asynchronous
 * (clusters take minutes), so {@see provision()} returns immediately and
 * {@see poll()} is called repeatedly until the cluster reports online.
 */
interface DatabaseBackend
{
    /** Stable backend key persisted on CloudDatabase.backend (e.g. digitalocean_managed_database). */
    public function key(): string;

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
}
