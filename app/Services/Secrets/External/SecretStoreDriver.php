<?php

declare(strict_types=1);

namespace App\Services\Secrets\External;

use App\Models\ExternalSecretStore;

/**
 * Fetches a single secret value from a customer's external store. The reference
 * is a driver-specific locator, by convention "path#field" — the part after `#`
 * selects a field within a structured secret (optional). Implementations only
 * READ; the value flows into the deploy env and is never persisted by dply.
 */
interface SecretStoreDriver
{
    public function fetch(ExternalSecretStore $store, string $reference): string;

    /**
     * Split a reference into [path, field|null] on the first `#`.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function splitReference(string $reference): array;
}
