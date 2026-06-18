<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services;

use InvalidArgumentException;

/**
 * Vault scope — which logical bucket of secrets a blob belongs to. v1 supports
 * the platform control-plane and per-organization scopes; all encrypt to the
 * same platform recipient for now (per-org keypairs are deferred).
 */
final class Scope
{
    private function __construct(public readonly string $key) {}

    public static function platform(): self
    {
        return new self('platform');
    }

    public static function org(int|string $id): self
    {
        $id = trim((string) $id);
        if ($id === '') {
            throw new InvalidArgumentException('Org scope requires an id.');
        }

        return new self('org-'.$id);
    }

    public static function fromKey(string $key): self
    {
        if ($key === 'platform') {
            return self::platform();
        }
        if (preg_match('/^org-[A-Za-z0-9]+$/', $key)) {
            return new self($key);
        }

        throw new InvalidArgumentException("Unknown vault scope: {$key}");
    }
}
