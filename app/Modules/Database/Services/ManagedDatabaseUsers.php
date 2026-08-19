<?php

declare(strict_types=1);

namespace App\Modules\Database\Services;

use App\Models\CloudDatabase;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Support\Servers\DatabaseConnectionTarget;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Users on a managed cluster, so an operator need not connect as the admin.
 *
 * Only the provider's own user API is used here. Making a user genuinely
 * read-only additionally requires GRANT/REVOKE run against the database itself,
 * which no provider exposes over HTTP — that is a separate step and is
 * deliberately not implied by anything in this class.
 */
class ManagedDatabaseUsers
{
    public function supports(CloudDatabase $database): bool
    {
        return (string) $database->backend === CloudDatabase::BACKEND_DIGITALOCEAN
            && filled($database->backend_id)
            && $database->engine !== CloudDatabase::ENGINE_REDIS;
    }

    /**
     * @return list<array{name: string, role: string}>
     */
    public function list(CloudDatabase $database): array
    {
        if (! $this->supports($database)) {
            return [];
        }

        try {
            $users = $this->service($database)->listDatabaseUsers((string) $database->backend_id);
        } catch (\Throwable) {
            // The picker degrades to "admin only" rather than breaking the modal.
            return [];
        }

        return array_map(
            static fn (array $user): array => ['name' => $user['name'], 'role' => $user['role']],
            $users,
        );
    }

    /**
     * Create a user and return credentials for it.
     *
     * @return array{name: string, password: string}
     */
    public function create(CloudDatabase $database, string $name): array
    {
        if (! $this->supports($database)) {
            throw new RuntimeException('This database does not support managing users from dply.');
        }

        $name = self::normalizeName($name);
        if ($name === '') {
            throw new RuntimeException('Enter a username.');
        }

        $created = $this->service($database)->createDatabaseUser((string) $database->backend_id, $name);
        if ($created['password'] === '') {
            throw new RuntimeException('The provider did not return a password for the new user.');
        }

        return ['name' => $created['name'], 'password' => $created['password']];
    }

    /**
     * The password for a named user, read back from the provider.
     *
     * Passwords are not stored by dply for users it did not provision, so the
     * connection hand-off resolves them at click time.
     */
    public function passwordFor(CloudDatabase $database, string $name): ?string
    {
        if (! $this->supports($database)) {
            return null;
        }

        try {
            foreach ($this->service($database)->listDatabaseUsers((string) $database->backend_id) as $user) {
                if ($user['name'] === $name && $user['password'] !== '') {
                    return $user['password'];
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /** Provider usernames are conservative: lowercase, alphanumeric, dashes. */
    public static function normalizeName(string $name): string
    {
        $name = Str::of($name)->lower()->replaceMatches('/[^a-z0-9_-]+/', '-')->trim('-')->value();

        return mb_substr($name, 0, 32);
    }

    /** A sensible default name for a person's own read-mostly login. */
    public static function suggestedName(string $seed): string
    {
        $base = self::normalizeName($seed);

        return $base !== '' ? $base : 'dply-user';
    }

    /** True when the target is the cluster's admin account. */
    public static function isAdmin(DatabaseConnectionTarget $target, string $username): bool
    {
        return $username === '' || $username === $target->username;
    }

    private function service(CloudDatabase $database): DigitalOceanService
    {
        $database->loadMissing('providerCredential');
        $credential = $database->providerCredential;
        if ($credential === null) {
            throw new RuntimeException('The database has no DigitalOcean credential.');
        }

        return new DigitalOceanService($credential);
    }
}
