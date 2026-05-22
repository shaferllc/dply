<?php

declare(strict_types=1);

namespace App\Services\RemoteCli;

/**
 * Risk classification applied to every wp-cli / artisan command before
 * dispatch. Drives the permission gate (Q17): read + mutating-recoverable
 * are open to any org member; destructive requires admin/owner + an
 * explicit confirm-by-site-name modal.
 *
 * Unknown commands default to {@see self::Destructive} as a failsafe.
 */
enum RiskLevel: string
{
    /** Pure inspection — output only, no DB or filesystem mutation. */
    case Read = 'read';

    /**
     * Changes state but the change is straightforward to back out
     * (install a plugin → uninstall it; migrate forward → migrate
     * back). Allowed for any member.
     */
    case MutatingRecoverable = 'mutating_recoverable';

    /**
     * Writes that may lose data or require a database restore to
     * undo. Drops, search-replace --all-tables, salts regeneration,
     * tinker, anything not on a known allowlist.
     */
    case Destructive = 'destructive';

    public function requiresAdmin(): bool
    {
        return $this === self::Destructive;
    }

    public function requiresConfirmation(): bool
    {
        return $this === self::Destructive;
    }
}
