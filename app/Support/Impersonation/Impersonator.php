<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Admin\PlatformAdmins;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Platform-admin "log in as user" impersonation.
 *
 * Session-based: the real admin's id (+ their active org, for clean restore) is
 * stashed under one session key while the web guard is swapped to the target
 * user. An always-visible banner + the leave() path return the admin to their
 * own session.
 *
 * Guardrails (enforced in start()):
 *   - caller must pass the viewPlatformAdmin gate (checked at the route),
 *   - cannot impersonate a platform admin (privilege-escalation safety),
 *   - cannot impersonate yourself,
 *   - cannot start a new impersonation while already impersonating.
 *
 * Note on the admin check: we test the configured allow list directly rather
 * than the gate, because the gate treats EVERY user as an admin in local/testing
 * — using it here would block all impersonation in dev.
 */
final class Impersonator
{
    private const SESSION_KEY = 'dply.impersonator';

    public function isImpersonating(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    public function impersonatorId(): ?string
    {
        $data = Session::get(self::SESSION_KEY);

        return is_array($data) ? ($data['id'] ?? null) : null;
    }

    /**
     * Begin impersonating $target as $admin. Returns the target on success.
     *
     * @throws RuntimeException on any guardrail violation
     */
    public function start(User $admin, User $target): User
    {
        if ($this->isImpersonating()) {
            throw new RuntimeException('Already impersonating — leave the current session first.');
        }

        if ($admin->is($target)) {
            throw new RuntimeException('You cannot impersonate yourself.');
        }

        if ($this->isPlatformAdmin($target)) {
            throw new RuntimeException('You cannot impersonate another platform admin.');
        }

        $adminOrgId = Session::get('current_organization_id');

        Auth::login($target);

        $targetOrg = $target->organizations()->oldest()->first();

        Session::put(self::SESSION_KEY, [
            'id' => $admin->getKey(),
            'organization_id' => $adminOrgId,
            'at' => now()->timestamp,
        ]);
        Session::put('current_organization_id', $targetOrg?->getKey());
        Session::forget('current_team_id');

        $this->audit($targetOrg ?? $target->organizations()->oldest()->first(), $admin, $target, 'impersonation.started');

        return $target;
    }

    /**
     * Stop impersonating and restore the original admin session.
     * Returns the restored admin, or null if not impersonating.
     */
    public function leave(): ?User
    {
        $data = Session::get(self::SESSION_KEY);
        if (! is_array($data) || empty($data['id'])) {
            return null;
        }

        $target = Auth::user();
        $admin = User::find($data['id']);

        if ($admin === null) {
            // Original admin vanished — fail safe by fully logging out.
            Auth::logout();
            Session::forget(self::SESSION_KEY);

            return null;
        }

        Auth::login($admin);
        Session::put('current_organization_id', $data['organization_id'] ?? null);
        Session::forget('current_team_id');
        Session::forget(self::SESSION_KEY);

        if ($target instanceof User) {
            $org = $target->organizations()->oldest()->first() ?? $admin->organizations()->oldest()->first();
            $this->audit($org, $admin, $target, 'impersonation.stopped');
        }

        return $admin;
    }

    private function isPlatformAdmin(User $user): bool
    {
        return in_array(Str::lower($user->email), PlatformAdmins::emails(), true);
    }

    private function audit(?Organization $organization, User $admin, User $target, string $action): void
    {
        if ($organization === null) {
            return;
        }

        AuditLog::log($organization, $admin, $action, $target, null, [
            'target_user_id' => $target->getKey(),
            'target_email' => $target->email,
        ]);
    }
}
