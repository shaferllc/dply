<?php

declare(strict_types=1);

namespace App\Services\Secrets\Sources;

use App\Services\Secrets\Contracts\SecretSource;
use App\Services\Secrets\Scope;
use RuntimeException;

/**
 * Fast break-glass bundle (W1): the prod box's recovery SSH key + Postgres
 * superuser credentials, as a small JSON blob. Escrowed age-encrypted off-box so
 * an operator can get back into the box and its DB WITHOUT first restoring the
 * whole control-plane database (which would itself need these very secrets).
 *
 * Sourced from operator-provided config (secret_vault.critical_keys); throws if
 * nothing is configured so it never silently escrows an empty bundle.
 */
final class CriticalKeysSource implements SecretSource
{
    public function name(): string
    {
        return 'critical-keys';
    }

    public function gather(Scope $scope): string
    {
        $cfg = (array) config('secret_vault.critical_keys');
        $bundle = [];

        $sshPath = $cfg['ssh_recovery_key_path'] ?? null;
        if (is_string($sshPath) && $sshPath !== '') {
            if (! is_file($sshPath) || ! is_readable($sshPath)) {
                throw new RuntimeException("recovery SSH key not readable: {$sshPath}");
            }
            $bundle['ssh_recovery_key'] = (string) file_get_contents($sshPath);
        }

        $pgPassword = $cfg['pg_password'] ?? null;
        if (is_string($pgPassword) && $pgPassword !== '') {
            $bundle['pg_superuser'] = (string) ($cfg['pg_superuser'] ?? 'postgres');
            $bundle['pg_password'] = $pgPassword;
        }

        if ($bundle === []) {
            throw new RuntimeException('critical-keys: nothing configured (set SECRET_VAULT_CRITICAL_* env).');
        }

        $bundle['captured_at'] = gmdate('Y-m-d\TH:i:s\Z');

        return (string) json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
