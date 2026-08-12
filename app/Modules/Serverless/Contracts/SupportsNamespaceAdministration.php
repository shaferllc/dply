<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Contracts;

/**
 * A backend whose namespace is itself administrable — credentials that can be
 * minted and revoked, and log forwarding that can be pointed at a third-party
 * service.
 *
 * Separate from {@see ServerlessFunctionProvisioner} because these operate on
 * the tenancy that holds the functions, not on any one function.
 */
interface SupportsNamespaceAdministration
{
    /**
     * @return array{ok: bool, error: ?string, keys: list<array<string, mixed>>}
     */
    public function listAccessKeys(string $namespace): array;

    /**
     * Mint a new key. The secret is returned exactly once — the caller must
     * show or store it now.
     *
     * @return array{ok: bool, error: ?string, key: ?array<string, mixed>}
     */
    public function createAccessKey(string $namespace, string $label): array;

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function deleteAccessKey(string $namespace, string $keyId): array;

    /**
     * Current log-forwarding destination, or null when logs stay on-platform.
     *
     * @return array{ok: bool, error: ?string, forwarding: ?array<string, mixed>}
     */
    public function logForwarding(string $namespace): array;

    /**
     * Point the namespace's function logs at a third-party service. Passing
     * null for `$destination` disables forwarding.
     *
     * @param  array<string, mixed>|null  $destination
     * @return array{ok: bool, error: ?string}
     */
    public function setLogForwarding(string $namespace, ?array $destination): array;
}
