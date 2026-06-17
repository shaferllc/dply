<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteAccessGate;
use App\Models\SiteAccessGatePassword;
use App\Models\SiteBasicAuthUser;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SiteAccessGateService
{
    public function addFormGatePassword(Site $site, string $label, string $password): SiteAccessGatePassword
    {
        $label = trim($label);
        $password = trim($password);

        if ($label === '') {
            throw ValidationException::withMessages([
                'new_form_gate_label' => __('Enter a label so you can tell who used each password.'),
            ]);
        }

        if (strlen($label) > 64) {
            throw ValidationException::withMessages([
                'new_form_gate_label' => __('Label must be 64 characters or fewer.'),
            ]);
        }

        if ($password === '') {
            throw ValidationException::withMessages([
                'new_form_gate_password' => __('Enter a password to enable the password gate.'),
            ]);
        }

        if (strlen($password) < 8 || strlen($password) > 255) {
            throw ValidationException::withMessages([
                'new_form_gate_password' => __('Password must be 8–255 characters.'),
            ]);
        }

        $this->markAllBasicAuthUsersForRemoval($site);

        $gate = SiteAccessGate::query()->firstOrNew(['site_id' => $site->id]);
        if (! $gate->exists || $gate->cookie_secret === '') {
            $gate->cookie_secret = Str::random(48);
        }

        $gate->method = SiteAccessGate::METHOD_FORM_PASSWORD;
        $gate->password_salt = null;
        $gate->password_verifier = null;
        $gate->cookie_secret = Str::random(48);
        $gate->save();

        $existing = SiteAccessGatePassword::query()
            ->where('site_id', $site->id)
            ->where('label', $label)
            ->first();

        $salt = bin2hex(random_bytes(16));

        if ($existing !== null) {
            $existing->password_salt = $salt;
            $existing->password_verifier = hash('sha256', $salt.$password);
            $existing->pending_removal_at = null;
            $existing->save();

            return $existing->refresh();
        }

        $sortOrder = (int) SiteAccessGatePassword::query()->where('site_id', $site->id)->max('sort_order');

        return SiteAccessGatePassword::query()->create([
            'site_id' => $site->id,
            'label' => $label,
            'password_salt' => $salt,
            'password_verifier' => hash('sha256', $salt.$password),
            'sort_order' => $sortOrder + 1,
        ]);
    }

    /**
     * @deprecated Use {@see addFormGatePassword()} with an explicit label.
     */
    public function syncFormPassword(Site $site, string $password): SiteAccessGate
    {
        $this->addFormGatePassword($site, 'Default', $password);

        return SiteAccessGate::query()->where('site_id', $site->id)->firstOrFail();
    }

    public function markFormGatePasswordForRemoval(Site $site, string $passwordId): void
    {
        SiteAccessGatePassword::query()
            ->where('site_id', $site->id)
            ->where('id', $passwordId)
            ->whereNull('pending_removal_at')
            ->update(['pending_removal_at' => now()]);
    }

    public function markAllFormGatePasswordsForRemoval(Site $site): void
    {
        SiteAccessGatePassword::query()
            ->where('site_id', $site->id)
            ->whereNull('pending_removal_at')
            ->update(['pending_removal_at' => now()]);
    }

    public function setMethod(Site $site, string $method): SiteAccessGate
    {
        if (! in_array($method, [
            SiteAccessGate::METHOD_OFF,
            SiteAccessGate::METHOD_BASIC_AUTH,
            SiteAccessGate::METHOD_FORM_PASSWORD,
        ], true)) {
            throw ValidationException::withMessages([
                'access_gate_method' => __('Invalid access method.'),
            ]);
        }

        if ($method === SiteAccessGate::METHOD_FORM_PASSWORD) {
            throw ValidationException::withMessages([
                'access_gate_method' => __('Add at least one password gate credential to enable form password protection.'),
            ]);
        }

        $gate = SiteAccessGate::query()->firstOrNew(['site_id' => $site->id]);
        if (! $gate->exists) {
            $gate->cookie_secret = Str::random(48);
        }

        if ($method === SiteAccessGate::METHOD_OFF) {
            $this->markAllBasicAuthUsersForRemoval($site);
            $this->markAllFormGatePasswordsForRemoval($site);
            $gate->method = SiteAccessGate::METHOD_OFF;
            $gate->password_salt = null;
            $gate->password_verifier = null;
        } elseif ($method === SiteAccessGate::METHOD_BASIC_AUTH) {
            $this->markAllFormGatePasswordsForRemoval($site);
            $gate->method = SiteAccessGate::METHOD_BASIC_AUTH;
            $gate->password_salt = null;
            $gate->password_verifier = null;
        }

        $gate->save();

        return $gate->refresh();
    }

    public function disable(Site $site): void
    {
        $this->setMethod($site, SiteAccessGate::METHOD_OFF);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function configPayload(Site $site): ?array
    {
        $site->loadMissing(['accessGate', 'accessGatePasswords', 'domains']);
        $gate = $site->accessGate;

        if ($gate === null || ! $gate->isFormPasswordActive()) {
            return null;
        }

        if (! $site->webserverSupportsFormPasswordGate()) {
            return null;
        }

        $passwords = $site->enforceableAccessGatePasswords()
            ->map(static fn (SiteAccessGatePassword $row): array => [
                'id' => (string) $row->id,
                'label' => (string) $row->label,
                'password_salt' => (string) $row->password_salt,
                'password_verifier' => (string) $row->password_verifier,
            ])
            ->values()
            ->all();

        if ($passwords === []) {
            return null;
        }

        $hostnames = collect($site->webserverHostnames())
            ->filter(fn ($h): bool => ($h) && $h !== '')
            ->map(fn (string $h): string => strtolower($h))
            ->unique()
            ->values()
            ->all();

        return [
            'mode' => 'password',
            'site_id' => (string) $site->id,
            'cookie_secret' => (string) $gate->cookie_secret,
            'passwords' => $passwords,
            'hostnames' => $hostnames,
            'secure_cookies' => $site->ssl_status === Site::SSL_ACTIVE,
        ];
    }

    public function markAllBasicAuthUsersForRemoval(Site $site): void
    {
        SiteBasicAuthUser::query()
            ->where('site_id', $site->id)
            ->whereNull('pending_removal_at')
            ->update(['pending_removal_at' => now()]);
    }

    public function ensureBasicAuthMethod(Site $site): void
    {
        $gate = SiteAccessGate::query()->firstOrNew(['site_id' => $site->id]);
        if (! $gate->exists) {
            $gate->cookie_secret = Str::random(48);
        }

        if ($gate->method === SiteAccessGate::METHOD_FORM_PASSWORD) {
            return;
        }

        $gate->method = SiteAccessGate::METHOD_BASIC_AUTH;
        $gate->password_salt = null;
        $gate->password_verifier = null;
        $gate->save();
    }
}
